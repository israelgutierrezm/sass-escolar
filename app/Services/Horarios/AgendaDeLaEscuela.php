<?php

declare(strict_types=1);

namespace App\Services\Horarios;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\DisponibilidadDocente;
use App\Models\ControlEscolar\HorarioAsignaturaGrupo;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la escuela YA tiene agendado, listo para comprobar contra ello.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * El generador y el aplicador cargaban esto cada uno por su lado, y los dos lo
 * hacían mal de la misma manera: metían los bloques guardados SIN DOCENTE. Como
 * un bloque sin persona no ocupa a nadie, pasaban dos cosas silenciosas.
 *
 * La primera es que los topes de carga sólo contaban lo de la corrida en curso:
 * generar el grupo A y luego el B le cargaba al mismo docente su tope completo
 * dos veces. Cada horario era válido por separado y juntos no cabían en una
 * semana.
 *
 * La segunda es peor: el mismo docente podía quedar en dos grupos A LA MISMA
 * HORA, siempre que el horario del otro grupo ya estuviera guardado. El choque
 * de aula sí se detectaba —el aula sí viajaba— y el de la persona no, así que
 * el error aparecía sólo cuando alguien miraba los dos horarios juntos.
 *
 * Cargarlo en un solo lugar es lo que impide que las dos copias vuelvan a
 * separarse.
 */
final class AgendaDeLaEscuela
{
    /**
     * @param  int[]  $excepto  materias cuyo horario se va a reemplazar y por
     *                          tanto no cuenta como ocupado
     */
    public static function actual(array $excepto = []): Agenda
    {
        $agenda = new Agenda(AsignaturaGrupo::query()->pluck('grupo_id', 'id')->all());
        $docenteDe = self::docentePorMateria();

        HorarioAsignaturaGrupo::query()
            ->when($excepto !== [], fn ($q) => $q->whereNotIn('asignatura_grupo_id', $excepto))
            ->get()
            ->each(fn (HorarioAsignaturaGrupo $h) => $agenda->ocupar(new Bloque(
                $h->asignatura_grupo_id,
                (int) $h->dia_semana,
                DisponibilidadDocente::aMinutos((string) $h->hora_inicio),
                DisponibilidadDocente::aMinutos((string) $h->hora_fin),
                $docenteDe[$h->asignatura_grupo_id] ?? null,
                $h->aula_id,
                (string) ($h->modalidad ?? 'presencial'),
            )));

        return $agenda;
    }

    /**
     * Quién imparte cada materia.
     *
     * Con titular y adjunto gana el TITULAR: es de quien cuelga la carga y el
     * que no puede estar en dos lugares. El adjunto acompaña, y contarlo como
     * ocupado bloquearía huecos que en la práctica sí existen.
     *
     * @return array<int, int> asignatura_grupo_id => persona_id
     */
    private static function docentePorMateria(): array
    {
        $porMateria = [];

        foreach (DB::table('docente_asignatura_grupo')->get() as $fila) {
            $materia = (int) $fila->asignatura_grupo_id;

            // El titular pisa a cualquier otro; el resto sólo llena el hueco.
            if ($fila->tipo === 'titular' || ! isset($porMateria[$materia])) {
                $porMateria[$materia] = (int) $fila->persona_id;
            }
        }

        return $porMateria;
    }
}
