<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\ReglaMatricula;
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
     * Llave del contador: define cada cuánto se reinicia la numeración y sobre
     * qué se cuenta.
     *
     * Son dos decisiones independientes —sobre qué, y si se reinicia cada año—,
     * así que la llave se arma juntando las dos partes. Su formato importa:
     * cambiarlo reiniciaría de facto todos los contadores de la escuela, porque
     * las filas viejas dejarían de encontrarse.
     */
    public function claveContador(ReglaMatricula $regla, Oferta $oferta, int $anio): string
    {
        $sobre = match ($regla->consecutivo_por) {
            null => 'global',
            'campus' => "campus:{$oferta->campus_id}",
            'nivel' => 'nivel:'.($oferta->carrera?->nivel_estudios_id ?? 0),
            'carrera' => "carrera:{$oferta->carrera_id}",
            'plan' => "plan:{$oferta->plan_id}",
            default => throw new RuntimeException(
                "Ámbito de consecutivo no reconocido: {$regla->consecutivo_por}"
            ),
        };

        return $regla->consecutivo_anual ? "{$sobre}|anio:{$anio}" : $sobre;
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
     * Sustituye los tokens de la plantilla. El consecutivo se rellena con
     * ceros según la cantidad de "#" del token: {####} → 0007.
     */
    private function renderizar(string $plantilla, Oferta $oferta, int $anio, int $consecutivo): string
    {
        $salida = strtr($plantilla, [
            '{AAAA}' => (string) $anio,
            '{AA}' => substr((string) $anio, -2),
            '{NIVEL}' => (string) $oferta->carrera?->nivelEstudios?->clave,
            '{CARRERA}' => (string) $oferta->carrera?->clave,
            '{PLAN}' => (string) $oferta->plan?->clave,
            '{CAMPUS}' => (string) $oferta->campus?->clave,
        ]);

        return preg_replace_callback(
            '/\{(#+)\}/',
            fn (array $m) => str_pad((string) $consecutivo, strlen($m[1]), '0', STR_PAD_LEFT),
            $salida
        ) ?? $salida;
    }
}
