<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Permanencia\Alerta;

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
        $valor = $alerta->valor_observado === null
            ? '—'
            : rtrim(rtrim(number_format((float) $alerta->valor_observado, 2, '.', ''), '0'), '.');

        $umbral = $alerta->umbral === null
            ? '—'
            : rtrim(rtrim(number_format((float) $alerta->umbral, 2, '.', ''), '0'), '.');

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
    public function respaldo(Alerta $alerta): string
    {
        $materia = $alerta->asignaturaGrupo?->planMateria?->asignatura?->nombre;

        return 'Se registró una señal de seguimiento'
            .($materia === null ? '' : ' en '.$materia)
            .' por la regla «'.($alerta->regla?->nombre ?? '—').'».';
    }
}
