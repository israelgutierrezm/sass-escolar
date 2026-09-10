<?php

declare(strict_types=1);

namespace App\Services\Asistencia;

use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Support\Facades\DB;

/**
 * Pasar lista de una materia: leer la hoja de una sesión y guardarla.
 *
 * ── Una sola verdad ────────────────────────────────────────────────────────
 * La escritura vivía dentro de `PaseListaController`; la app móvil necesitaba lo
 * mismo desde su API. En vez de copiarlo —y arriesgar que una copia deje de
 * revivir la fila borrada, o de acotar a la materia propia—, la regla vive aquí
 * y la usan los dos: la pantalla web y `DocenteApiController`.
 *
 * ── La lista se guarda COMPLETA, no alumno por alumno ──────────────────────
 * Pasar lista es un acto único sobre el grupo; guardar de a uno dejaría sesiones
 * a medias si quien pasa lista se distrae. Repasar el mismo día CORRIGE, no
 * duplica: el único es (inscripción, fecha, modalidad), y se REVIVE la fila
 * borrada —un `updateOrCreate` normal no la ve y choca con el único de la base—.
 */
class PaseDeLista
{
    /**
     * Los cuatro estatus válidos, del MODELO y no de una lista suelta: eran dos
     * declaraciones de lo mismo y ya habían divergido.
     */
    public const ESTATUS = [
        AsistenciaClase::PRESENTE,
        AsistenciaClase::RETARDO,
        AsistenciaClase::FALTA,
        AsistenciaClase::JUSTIFICADA,
    ];

    public const MODALIDADES = ['unica', 'teorica', 'practica'];

    /**
     * Guarda la lista completa de una sesión. Devuelve cuántas se guardaron.
     *
     * Sólo toca inscripciones de ESTA materia: un id ajeno en el arreglo no debe
     * crear un renglón de asistencia en un grupo que no es suyo.
     *
     * @param  array<int, array{inscripcion_id: int|string, estatus: string, observacion?: string|null}>  $asistencias
     */
    public function guardar(AsignaturaGrupo $asignaturaGrupo, string $fecha, string $modalidad, array $asistencias, int $personaId): int
    {
        $suyas = Inscripcion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->pluck('id')
            ->flip();

        $guardadas = 0;

        DB::transaction(function () use ($asistencias, $suyas, $fecha, $modalidad, $personaId, &$guardadas) {
            foreach ($asistencias as $fila) {
                if (! $suyas->has((int) $fila['inscripcion_id'])) {
                    continue;
                }

                AsistenciaClase::actualizarOReviver(
                    [
                        'inscripcion_id' => $fila['inscripcion_id'],
                        'fecha' => $fecha,
                        'modalidad' => $modalidad,
                    ],
                    [
                        'estatus' => $fila['estatus'],
                        'observacion' => $fila['observacion'] ?? null,
                        // La foránea apunta a `personas`, no a `usuarios`: quien
                        // pasa lista es el docente como PERSONA, lo que sigue
                        // teniendo sentido si su cuenta desaparece.
                        'registrada_por' => $personaId,
                    ],
                );

                $guardadas++;
            }
        });

        return $guardadas;
    }

    /**
     * La hoja de una sesión: cada alumno (sin los dados de baja) con lo ya
     * marcado ese día —null si todavía no— y su acumulado de faltas y retardos,
     * que es lo que dice si alguien está en riesgo por inasistencias.
     *
     * @return array<int, array<string, mixed>>
     */
    public function hoja(AsignaturaGrupo $asignaturaGrupo, string $fecha, string $modalidad): array
    {
        $inscripciones = Inscripcion::query()
            ->with([
                'matriculaOferta:id,persona_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'situacion:id,clave',
            ])
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->get()
            ->reject(fn (Inscripcion $i) => $i->situacion?->clave === 'baja')
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->values();

        $ids = $inscripciones->pluck('id');

        $delDia = AsistenciaClase::query()
            ->whereIn('inscripcion_id', $ids)
            ->whereDate('fecha', $fecha)
            ->where('modalidad', $modalidad)
            ->get()
            ->keyBy('inscripcion_id');

        $historial = AsistenciaClase::query()
            ->whereIn('inscripcion_id', $ids)
            ->selectRaw('inscripcion_id, estatus, COUNT(*) c')
            ->groupBy('inscripcion_id', 'estatus')
            ->get()
            ->groupBy('inscripcion_id');

        return $inscripciones
            ->map(function (Inscripcion $i) use ($delDia, $historial) {
                $conteo = ($historial->get($i->id) ?? collect())->pluck('c', 'estatus');
                $total = $conteo->sum();
                $presentes = (int) $conteo->get('presente', 0) + (int) $conteo->get('retardo', 0);

                return [
                    'inscripcion_id' => $i->id,
                    'matricula' => $i->matriculaOferta?->matricula,
                    'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                    'estatus' => $delDia->get($i->id)?->estatus,
                    'observacion' => $delDia->get($i->id)?->observacion,
                    'faltas' => (int) $conteo->get('falta', 0),
                    'retardos' => (int) $conteo->get('retardo', 0),
                    'porcentaje' => $total === 0 ? null : (int) round($presentes * 100 / $total),
                ];
            })
            ->values()
            ->all();
    }
}
