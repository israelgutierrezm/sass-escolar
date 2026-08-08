<?php

declare(strict_types=1);

namespace App\Services\Horarios;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\DisponibilidadDocente;
use App\Models\ControlEscolar\HorarioAsignaturaGrupo;
use Illuminate\Support\Facades\DB;

/**
 * Escribe un horario propuesto. Es el único sitio que toca
 * `horarios_asignatura_grupo`.
 *
 * ── Lo que llega del cliente se vuelve a validar ───────────────────────────
 * La propuesta viaja al navegador, se revisa y regresa. Entre esas dos cosas
 * pueden haber pasado veinte minutos: alguien más pudo capturar un horario a
 * mano, cambiar una disponibilidad o borrar una materia. Y el navegador puede
 * mandar cualquier cosa, no sólo lo que se le propuso.
 *
 * Así que aquí se revisa todo otra vez y contra el estado ACTUAL de la base. Un
 * validador que confía en lo que le devuelven es un validador decorativo.
 *
 * ── Reemplaza lo de esas materias, no lo de la escuela ─────────────────────
 * Aplicar borra los bloques de las materias que vienen en la propuesta y pone
 * los nuevos. Un horario a medias —lo viejo y lo nuevo mezclados— sería peor
 * que cualquiera de los dos completos.
 */
class AplicadorHorario
{
    /**
     * @param  array<int, array<string, mixed>>  $bloques
     * @return array{aplicados: int, materias: int}
     */
    public function aplicar(array $bloques, bool $asignarDocentes = false): array
    {
        $materias = $this->materiasDe($bloques);

        // Las materias de la propuesta se van a reemplazar, así que chocar con
        // su horario viejo no cuenta: se compara contra el resto.
        $this->rechazarChoques($bloques, $materias, array_keys($materias));

        return DB::transaction(function () use ($bloques, $materias, $asignarDocentes) {
            /*
             * Fuera lo anterior de ESAS materias.
             *
             * Si se conservara, aplicar dos veces dejaría el horario duplicado,
             * y aplicar una propuesta más corta que la anterior dejaría
             * colgando los bloques que ya no existen.
             */
            HorarioAsignaturaGrupo::query()
                ->whereIn('asignatura_grupo_id', array_keys($materias))
                ->delete();

            foreach ($bloques as $bloque) {
                HorarioAsignaturaGrupo::create([
                    'asignatura_grupo_id' => $bloque['asignatura_grupo_id'],
                    'dia_semana' => $bloque['dia'],
                    'hora_inicio' => $bloque['hora_inicio'],
                    'hora_fin' => $bloque['hora_fin'],
                    'aula_id' => $bloque['aula_id'] ?? null,
                    'modalidad' => $bloque['modalidad'] ?? 'presencial',
                ]);
            }

            if ($asignarDocentes) {
                $this->asignarDocentes($bloques, $materias);
            }

            return ['aplicados' => count($bloques), 'materias' => count($materias)];
        });
    }

    /**
     * Un bloque suelto, capturado a mano.
     *
     * Agrega SIN borrar nada, y por eso se compara contra todo lo que ya está
     * —incluido el horario de su propia materia—: aquí no se reemplaza, se
     * suma.
     *
     * Pasa por la misma validación que la propuesta a propósito. Dos puertas al
     * mismo dato con dos criterios distintos es cómo se llena una base de
     * horarios imposibles: el generador tiene prohibido crear un choque y la
     * captura manual no puede ser el agujero por donde entran.
     *
     * @param  array<string, mixed>  $bloque
     */
    public function aplicarUno(array $bloque): HorarioAsignaturaGrupo
    {
        $materias = $this->materiasDe([$bloque]);
        $this->rechazarChoques([$bloque], $materias, reemplazan: []);

        return HorarioAsignaturaGrupo::create([
            'asignatura_grupo_id' => $bloque['asignatura_grupo_id'],
            'dia_semana' => $bloque['dia'],
            'hora_inicio' => $bloque['hora_inicio'],
            'hora_fin' => $bloque['hora_fin'],
            'aula_id' => $bloque['aula_id'] ?? null,
            'modalidad' => $bloque['modalidad'] ?? 'presencial',
        ]);
    }

    /**
     * Las materias que toca la propuesta, comprobando que existan.
     *
     * @param  array<int, array<string, mixed>>  $bloques
     * @return array<int, AsignaturaGrupo>
     */
    private function materiasDe(array $bloques): array
    {
        $ids = collect($bloques)->pluck('asignatura_grupo_id')->unique()->values();

        AvisoParaElUsuario::si($ids->isEmpty(), 422, 'La propuesta no trae ningún bloque que aplicar.');

        $materias = AsignaturaGrupo::query()
            ->with('grupo:id,clave')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        AvisoParaElUsuario::si(
            $materias->count() !== $ids->count(),
            422,
            'Alguna de las materias de la propuesta ya no existe. Vuelve a generarla.',
        );

        return $materias->all();
    }

    /**
     * Ni el grupo, ni el aula, ni el docente pueden estar en dos lugares a la vez.
     *
     * Se comprueba entre los bloques nuevos Y contra lo que ya está guardado de
     * OTRAS materias —las de la propuesta se van a reemplazar, así que chocar
     * con ellas no cuenta—.
     *
     * @param  array<int, array<string, mixed>>  $bloques
     * @param  array<int, AsignaturaGrupo>  $materias
     * @param  int[]  $reemplazan  materias cuyo horario viejo se va a borrar
     */
    private function rechazarChoques(array $bloques, array $materias, array $reemplazan): void
    {
        /*
         * Lo que ya existe y NO se va a reemplazar, con su docente y su grupo.
         *
         * Viene de `AgendaDeLaEscuela` para que sea EXACTAMENTE lo mismo que ve
         * el generador: cuando cada uno lo cargaba por su cuenta, los dos
         * perdían al docente de los bloques guardados y con él la posibilidad
         * de notar que esa persona ya daba clase a esa hora en otro grupo.
         */
        $agenda = AgendaDeLaEscuela::actual($reemplazan);

        foreach ($bloques as $crudo) {
            $bloque = new Bloque(
                (int) $crudo['asignatura_grupo_id'],
                (int) $crudo['dia'],
                DisponibilidadDocente::aMinutos((string) $crudo['hora_inicio']),
                DisponibilidadDocente::aMinutos((string) $crudo['hora_fin']),
                isset($crudo['persona_id']) ? (int) $crudo['persona_id'] : null,
                isset($crudo['aula_id']) ? (int) $crudo['aula_id'] : null,
                (string) ($crudo['modalidad'] ?? 'presencial'),
            );

            $materia = $materias[$bloque->asignaturaGrupoId];
            $donde = $materia->grupo?->clave ?? 'ese grupo';
            $cuando = $this->cuando($bloque);

            AvisoParaElUsuario::aMenosQue(
                $bloque->fin > $bloque->inicio,
                422,
                "Hay un bloque que termina antes de empezar ({$cuando}).",
            );

            AvisoParaElUsuario::aMenosQue(
                $agenda->grupoLibre((int) $materia->grupo_id, $bloque),
                422,
                "El grupo {$donde} tendría dos clases a la vez el {$cuando}.",
            );

            if ($bloque->aulaId !== null) {
                AvisoParaElUsuario::aMenosQue(
                    $agenda->aulaLibre($bloque->aulaId, $bloque),
                    422,
                    "Ese salón ya está ocupado el {$cuando}.",
                );
            }

            if ($bloque->personaId !== null) {
                AvisoParaElUsuario::aMenosQue(
                    $agenda->docenteLibre($bloque->personaId, $bloque),
                    422,
                    "El docente tendría dos clases a la vez el {$cuando}.",
                );
            }

            $agenda->ocupar($bloque);
        }
    }

    /**
     * Le pone docente a las materias que no tenían.
     *
     * Sólo a ésas: a quien ya tiene titular no se le toca. Reasignar en silencio
     * al aplicar un horario sería cambiar una decisión de la coordinación por la
     * vía de un botón que dice otra cosa.
     *
     * @param  array<int, array<string, mixed>>  $bloques
     * @param  array<int, AsignaturaGrupo>  $materias
     */
    private function asignarDocentes(array $bloques, array $materias): void
    {
        $propuesto = [];

        foreach ($bloques as $bloque) {
            if (! empty($bloque['persona_id'])) {
                $propuesto[(int) $bloque['asignatura_grupo_id']] = (int) $bloque['persona_id'];
            }
        }

        foreach ($propuesto as $asignaturaGrupoId => $personaId) {
            $yaTiene = DB::table('docente_asignatura_grupo')
                ->where('asignatura_grupo_id', $asignaturaGrupoId)
                ->exists();

            if ($yaTiene) {
                continue;
            }

            DB::table('docente_asignatura_grupo')->insert([
                'asignatura_grupo_id' => $asignaturaGrupoId,
                'persona_id' => $personaId,
                'tipo' => 'titular',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function cuando(Bloque $bloque): string
    {
        $dias = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

        return ($dias[$bloque->dia] ?? 'día '.$bloque->dia)
            .' de '.Bloque::hora($bloque->inicio).' a '.Bloque::hora($bloque->fin);
    }
}
