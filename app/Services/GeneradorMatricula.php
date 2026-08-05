<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\ReglaMatricula;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera la matrícula de un alumno según la regla configurada por la escuela.
 *
 * Se invoca en el ÚLTIMO paso de la conversión aspirante → alumno, dentro de
 * la misma transacción que crea la `matricula_oferta`.
 *
 * Dos garantías:
 *  1. La regla se resuelve de lo más específico a lo más general
 *     (plan → carrera → global), así una escuela puede tener un formato
 *     distinto para posgrado sin duplicar la regla en cada plan.
 *  2. El consecutivo se obtiene con un incremento ATÓMICO sobre
 *     `contadores_matricula`. Nunca se calcula con MAX(matricula)+1: bajo
 *     concurrencia dos administradores obtendrían el mismo número.
 *
 * `generar()` CONSUME el folio; `previsualizar()` no. Son operaciones
 * distintas a propósito — ver la nota en `previsualizar()`.
 */
class GeneradorMatricula
{
    /**
     * Devuelve la siguiente matrícula para una oferta. Consume el consecutivo:
     * llamarlo dos veces entrega dos números distintos.
     */
    public function generar(Oferta $oferta, ?int $anio = null): string
    {
        $anio ??= (int) now()->format('Y');
        $regla = $this->resolverRegla($this->conRelaciones($oferta));

        $consecutivo = $this->siguienteConsecutivo($this->claveContador($regla, $oferta, $anio));

        return $this->renderizar($regla->plantilla, $oferta, $anio, $consecutivo);
    }

    /**
     * Cómo QUEDARÍA la matrícula, sin gastar el folio.
     *
     * Existe para dos cosas: la vista previa de la pantalla de reglas —donde
     * uno prueba plantillas hasta que le gusta— y la sugerencia que se le
     * muestra al administrador antes de convertir a un aspirante.
     *
     * **Es una sugerencia, no una reserva.** Lee el contador y le suma uno sin
     * tocarlo, así que si entre la vista y la conversión alguien más convierte,
     * el número final será el siguiente. Reservarlo sería peor: cada vista
     * previa quemaría un folio y la numeración saldría con huecos.
     */
    public function previsualizar(Oferta $oferta, ?int $anio = null): string
    {
        $anio ??= (int) now()->format('Y');
        $regla = $this->resolverRegla($this->conRelaciones($oferta));

        $actual = (int) DB::table('contadores_matricula')
            ->where('clave', $this->claveContador($regla, $oferta, $anio))
            ->value('valor');

        return $this->renderizar($regla->plantilla, $oferta, $anio, $actual + 1);
    }

    /**
     * Igual que `previsualizar()`, pero con una regla que todavía no se guarda.
     *
     * La pantalla de reglas la usa para enseñar el resultado mientras se
     * teclea la plantilla, con una oferta de ejemplo.
     */
    public function ensayar(ReglaMatricula $regla, Oferta $oferta, int $consecutivo = 1, ?int $anio = null): string
    {
        return $this->renderizar(
            $regla->plantilla,
            $this->conRelaciones($oferta),
            $anio ?? (int) now()->format('Y'),
            $consecutivo,
        );
    }

    /**
     * Regla aplicable, de la más específica a la más general.
     */
    public function resolverRegla(Oferta $oferta): ReglaMatricula
    {
        $candidatas = [
            ['plan', $oferta->plan_id],
            ['carrera', $oferta->carrera_id],
            ['global', null],
        ];

        foreach ($candidatas as [$ambito, $ambitoId]) {
            $regla = ReglaMatricula::query()
                ->where('activo', true)
                ->where('ambito', $ambito)
                ->when($ambitoId === null,
                    fn ($q) => $q->whereNull('ambito_id'),
                    fn ($q) => $q->where('ambito_id', $ambitoId),
                )
                ->first();

            if ($regla !== null) {
                return $regla;
            }
        }

        throw new RuntimeException(
            'No hay una regla de matrícula configurada para esta oferta ni una regla global activa.'
        );
    }

    /**
     * Llave del contador: sobre qué se cuenta y cada cuándo vuelve al 1.
     *
     * Se arma con las dimensiones en su orden canónico y, al final, el reinicio.
     * Ese formato IMPORTA y no se puede tocar a la ligera: cambiarlo reiniciaría
     * de facto todos los contadores de la escuela, porque las filas viejas
     * dejarían de encontrarse y la numeración volvería a empezar en 1 contra
     * matrículas ya impresas.
     *
     * Sin dimensiones la llave es «global», que es la misma cadena que producía
     * la versión de una sola dimensión: por eso pasar de una a varias no
     * requirió renombrar nada.
     */
    public function claveContador(ReglaMatricula $regla, Oferta $oferta, int $anio): string
    {
        /*
         * Se recorren las dimensiones GUARDADAS, no las que `dimensiones()`
         * deja pasar.
         *
         * `dimensiones()` filtra a las conocidas para fijar el orden, y con eso
         * una dimensión inválida —de una migración a medias o de una edición a
         * mano en la base— se caería en silencio: la llave tendría una parte
         * menos y los alumnos se numerarían sobre un contador más ancho del que
         * la escuela cree. Un folio mal puesto sin avisar es peor que no poder
         * numerar, así que aquí se revienta.
         */
        $desconocidas = array_diff(
            $regla->consecutivo_dimensiones ?? [],
            ReglaMatricula::CONSECUTIVO_DIMENSIONES,
        );

        if ($desconocidas !== []) {
            throw new RuntimeException(
                'Dimensión de consecutivo no reconocida: '.implode(', ', $desconocidas)
            );
        }

        $partes = array_map(
            fn (string $dimension) => $this->parteDe($dimension, $oferta),
            $regla->dimensiones(),
        );

        if ($partes === []) {
            $partes = ['global'];
        }

        $reinicio = match ($regla->consecutivo_reinicia) {
            'nunca' => null,
            'anio' => "anio:{$anio}",
            // El ciclo escolar, para las escuelas que no piensan en años
            // naturales: una cuatrimestral reinicia en el cuatrimestre que
            // empieza, no en enero. Sin ciclo abierto se cae al año, que es lo
            // único que siempre se sabe —y no numerar no es una opción—.
            'ciclo' => ($ciclo = Ciclo::enCurso()) !== null ? "ciclo:{$ciclo->id}" : "anio:{$anio}",
            default => throw new RuntimeException(
                "Reinicio de consecutivo no reconocido: {$regla->consecutivo_reinicia}"
            ),
        };

        return implode('|', $reinicio === null ? $partes : [...$partes, $reinicio]);
    }

    /** El trozo de llave de una dimensión. */
    private function parteDe(string $dimension, Oferta $oferta): string
    {
        return match ($dimension) {
            'campus' => "campus:{$oferta->campus_id}",
            'nivel' => 'nivel:'.($oferta->carrera?->nivel_estudios_id ?? 0),
            'carrera' => "carrera:{$oferta->carrera_id}",
            'plan' => "plan:{$oferta->plan_id}",
            default => throw new RuntimeException(
                "Dimensión de consecutivo no reconocida: {$dimension}"
            ),
        };
    }

    /**
     * Incremento atómico del consecutivo.
     *
     * Usa el patrón canónico de MySQL `INSERT ... ON DUPLICATE KEY UPDATE` con
     * LAST_INSERT_ID(): en una sola sentencia crea el contador o lo incrementa,
     * y deja el nuevo valor en el LAST_INSERT_ID de ESTA sesión, de modo que
     * dos conexiones concurrentes nunca leen el mismo número.
     *
     * Depende de que `contadores_matricula` NO tenga columna AUTO_INCREMENT: si
     * la tuviera, el INSERT pisaría LAST_INSERT_ID() con el id de la fila nueva
     * y el primer consecutivo de cada llave saldría mal, generando duplicados.
     */
    private function siguienteConsecutivo(string $clave): int
    {
        DB::statement(
            'INSERT INTO contadores_matricula (clave, valor, created_at, updated_at)
             VALUES (?, LAST_INSERT_ID(1), NOW(), NOW())
             ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1), updated_at = NOW()',
            [$clave]
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS valor')->valor;
    }

    /** El nivel se lee a través de la carrera, así que hay que traerla. */
    private function conRelaciones(Oferta $oferta): Oferta
    {
        $oferta->loadMissing(['carrera.nivelEstudios', 'plan', 'campus']);

        return $oferta;
    }

    /**
     * Sustituye los tokens de la plantilla.
     *
     * Dos formas: `{CARRERA}` pone la clave entera y `{CARRERA:2}` sus dos
     * primeras letras. El recorte existe porque hay escuelas cuya clave de
     * carrera mide cinco caracteres y en la matrícula sólo caben dos; sin él,
     * el único camino era inventarse una clave falsa en el catálogo.
     *
     * El consecutivo va aparte, con su propio token: se rellena con ceros según
     * la cantidad de «#», {####} → 0007.
     */
    private function renderizar(string $plantilla, Oferta $oferta, int $anio, int $consecutivo): string
    {
        $valores = [
            'AAAA' => (string) $anio,
            'AA' => substr((string) $anio, -2),
            'MM' => now()->format('m'),
            'CICLO' => (string) Ciclo::enCurso()?->clave,
            'NIVEL' => (string) $oferta->carrera?->nivelEstudios?->clave,
            'CARRERA' => (string) $oferta->carrera?->clave,
            'PLAN' => (string) $oferta->plan?->clave,
            'CAMPUS' => (string) $oferta->campus?->clave,
        ];

        $salida = preg_replace_callback(
            '/\{([A-Z]+)(?::(\d+))?\}/',
            function (array $m) use ($valores) {
                // Un token que no existe se deja tal cual en vez de borrarse:
                // así se ve en la vista previa que está mal escrito, en lugar
                // de desaparecer sin decir nada.
                if (! array_key_exists($m[1], $valores)) {
                    return $m[0];
                }

                return isset($m[2]) ? mb_substr($valores[$m[1]], 0, (int) $m[2]) : $valores[$m[1]];
            },
            $plantilla,
        ) ?? $plantilla;

        return preg_replace_callback(
            '/\{(#+)\}/',
            fn (array $m) => str_pad((string) $consecutivo, strlen($m[1]), '0', STR_PAD_LEFT),
            $salida
        ) ?? $salida;
    }
}
