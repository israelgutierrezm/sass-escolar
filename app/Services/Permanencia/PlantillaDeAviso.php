<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Permanencia\Alerta;
use App\Permanencia\CatalogoMetricas;
use InvalidArgumentException;

/**
 * El texto que la escuela redacta para sus reglas.
 *
 * ── Un conjunto CERRADO de marcas, no una expresión libre ──────────────────
 * Es el mismo argumento que rechazó el campo de SQL en el constructor de
 * reportes y el de condición libre en las reglas: una plantilla que evalúe algo
 * arbitrario es una superficie de ejecución, y sobre todo **no se puede
 * explicar** — de una expresión sólo se puede repetir el texto.
 *
 * Aquí las marcas son cinco, se sustituyen por texto plano y **lo que no
 * reconoce se deja tal cual**. Borrarlo silenciosamente dejaría un hueco en
 * medio de la frase y quien la escribió creería que la marca funcionó.
 *
 * ── Y por qué el VALOR sí puede ir en el texto ─────────────────────────────
 * El aviso es del portal y se lee con la sesión abierta. El pedido prohíbe
 * mandar el dato «por correo, SMS o push» —canales que no exigen sesión— y pide
 * al alumno «pendientes y recomendaciones concretas»: «llevas 3 faltas seguidas
 * en Cálculo I» es exactamente eso, y «tienes una señal» no le sirve para nada.
 *
 * Lo que NO puede ir es el dato de una categoría SENSIBLE en un aviso dirigido a
 * la ESCUELA, porque ese va a un ROL —muchas personas, algunas sin el permiso
 * que abre el detalle—. Eso lo decide `AvisosDeSenales`, no la plantilla: aquí
 * sólo se sustituye lo que se pida.
 */
class PlantillaDeAviso
{
    /** Lo único que se sustituye. Todo lo demás se queda literal. */
    public const MARCAS = ['{alumno}', '{materia}', '{regla}', '{valor}', '{umbral}'];

    /**
     * Rellena la plantilla de una regla con los datos de su alerta.
     *
     * @param  bool  $conElDato  false deja `{valor}` y `{umbral}` fuera: es lo
     *                           que se usa para el aviso de una categoría
     *                           sensible dirigido a la escuela
     */
    public function rellenar(string $plantilla, Alerta $alerta, bool $conElDato = true): string
    {
        $unidad = CatalogoMetricas::de((string) $alerta->version?->metrica)['unidad'] ?? null;

        $valor = $this->conSuUnidad($alerta->valor_observado, $unidad);
        $umbral = $this->conSuUnidad($alerta->umbral, $unidad);

        return str_replace(
            self::MARCAS,
            [
                $alerta->matricula?->persona?->nombreCompleto() ?? 'el alumno',
                /*
                 * «tus clases» y no «su materia»: el aviso le habla AL ALUMNO, y
                 * mezclar la segunda persona con la tercera —«Tu asistencia en
                 * su materia»— se lee como una plantilla a medio rellenar. El
                 * respaldo entra cuando la regla mide sobre el ciclo entero y no
                 * sobre una materia concreta.
                 */
                $alerta->asignaturaGrupo?->planMateria?->asignatura?->nombre ?? 'tus clases',
                $alerta->regla?->nombre ?? 'una regla',
                $conElDato ? $valor : 'el valor registrado',
                $conElDato ? $umbral : 'el mínimo configurado',
            ],
            $plantilla,
        );
    }

    /**
     * El texto de una señal cuando la regla no trae plantilla.
     *
     * ── Y por qué NO se inventa un texto por métrica ───────────────────────
     * Sería adivinar cómo esta escuela le habla a sus alumnos, y saldría en
     * cientos de avisos. El respaldo dice lo que se puede decir con seguridad
     * —qué regla y sobre qué materia— y remite a la pantalla, que es donde el
     * dato está completo y con su explicación.
     */
    /**
     * Un número con la unidad que le corresponde a SU métrica.
     *
     * ── Por qué la unidad no la escribe quien redacta ─────────────────────
     * `{valor}` y `{umbral}` son números cuyo significado depende de lo que la
     * regla mide: 15 puede ser 15 %, 15 días o 15 sesiones. Antes la unidad
     * salía de la prosa —«va en {valor} %»— y nada comprobaba que casara con la
     * métrica, así que una regla que cuenta días de atraso con esa plantilla le
     * decía al alumno «va en 15 % y se pide 15 %». No falla, no avisa: dice otra
     * cosa. Se descubrió escribiendo una plantilla a mano para mirar la
     * pantalla.
     *
     * La unidad es una propiedad de la MÉTRICA y ahí vive ya
     * (`CatalogoMetricas`), así que se toma de ahí y quien redacta escribe sólo
     * la frase.
     */
    private function conSuUnidad(int|float|string|null $numero, ?string $unidad): string
    {
        if ($numero === null) {
            return '—';
        }

        // Sin decimales de relleno: «63» y no «63.00», pero «7.93» entero.
        $texto = rtrim(rtrim(number_format((float) $numero, 2, '.', ''), '0'), '.');

        if ($unidad === null) {
            return $texto;
        }

        $sufijo = $this->sufijoDe($unidad, (float) $numero);

        return $sufijo === '' ? $texto : $texto.' '.$sufijo;
    }

    /**
     * Cómo se dice esa unidad detrás del número, en singular o en plural.
     *
     * ── El conjunto es CERRADO, y si aparece uno nuevo REVIENTA ───────────
     * Es la guarda ruidosa de siempre: una métrica que declare una unidad que
     * esta tabla no conoce tiene que detenerse aquí, no salir en el aviso con
     * la palabra en plural pegada a un 1 —«1 sesiones»— ni sin unidad. El aviso
     * lo lee un alumno sobre sí mismo, y una frase mal armada es lo que le
     * quita autoridad a lo que dice.
     */
    private function sufijoDe(string $unidad, float $numero): string
    {
        /*
         * `calificación` no lleva sufijo: «tu promedio va en 7.93» se lee bien y
         * «7.93 calificación» no se dice. La unidad existe en el catálogo para
         * explicar QUÉ es el número, no para pegarse detrás.
         */
        $sinSufijo = ['calificación'];

        if (in_array($unidad, $sinSufijo, true)) {
            return '';
        }

        // El porcentaje va con espacio antes del signo, como manda el español.
        if ($unidad === '%') {
            return '%';
        }

        $singulares = [
            'sesiones' => 'sesión',
            'materias' => 'materia',
            'actividades' => 'actividad',
            'documentos' => 'documento',
            'cargos' => 'cargo',
            'días' => 'día',
        ];

        if (! array_key_exists($unidad, $singulares)) {
            throw new InvalidArgumentException(
                'La unidad «'.$unidad.'» no está en el vocabulario de los avisos. '
                .'Agrégala en PlantillaDeAviso::sufijoDe con su singular: sin eso el aviso '
                .'saldría diciendo «1 '.$unidad.'».',
            );
        }

        return abs($numero - 1.0) < 0.0001 ? $singulares[$unidad] : $unidad;
    }

    /**
     * Lo que le sobra a una plantilla, o null si está bien.
     *
     * La unidad la pone el sistema, así que escribirla otra vez produce «63 %
     * %» o «15 días días». Se rehúsa al GUARDAR y no al mostrar: quien la
     * redacta está delante y puede corregirla; descubrirlo en el aviso de un
     * alumno es descubrirlo tarde.
     */
    public function unidadDeMas(string $plantilla): ?string
    {
        $unidades = ['%', 'días', 'dias', 'sesiones', 'materias', 'actividades',
            'documentos', 'cargos'];

        foreach (['{valor}', '{umbral}'] as $marca) {
            foreach ($unidades as $unidad) {
                if (str_contains($plantilla, $marca.' '.$unidad)
                    || str_contains($plantilla, $marca.$unidad)) {
                    return $unidad;
                }
            }
        }

        return null;
    }

    public function respaldo(Alerta $alerta): string
    {
        $materia = $alerta->asignaturaGrupo?->planMateria?->asignatura?->nombre;

        return 'Se registró una señal de seguimiento'
            .($materia === null ? '' : ' en '.$materia)
            .' por la regla «'.($alerta->regla?->nombre ?? '—').'».';
    }
}
