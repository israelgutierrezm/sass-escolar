<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Support\Facades\DB;

/**
 * Capturar calificaciones por componente: el estado del acta y la escritura.
 *
 * ── Una sola verdad ────────────────────────────────────────────────────────
 * La escritura vivía dentro de `CapturaCalificacionesController`; la app móvil
 * necesitaba lo mismo desde su API. En vez de copiarlo —y arriesgar que una
 * copia deje de acotar a la materia propia o de respetar los cortes del
 * calendario—, la regla vive aquí y la usan los dos.
 *
 * ── Qué NO hace ────────────────────────────────────────────────────────────
 * No cierra ni corrige el acta: eso es un acto deliberado e irreversible que
 * vuelca al historial con folio, y se queda en `AsentadorActa` y en su
 * controlador. Esto es sólo la captura del número por componente.
 */
class CapturaDeCalificaciones
{
    public function __construct(private readonly CalendarioCaptura $calendario) {}

    /** La corrección en curso, si la hay. Mientras exista, la captura sigue abierta. */
    public function correccionAbierta(AsignaturaGrupo $asignaturaGrupo): ?Acta
    {
        return Acta::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->where('situacion', Acta::ABIERTA)
            ->whereNotNull('acta_origen_id')
            ->latest('id')
            ->first();
    }

    /** El acta firmada más reciente de la materia. */
    public function actaCerrada(AsignaturaGrupo $asignaturaGrupo): ?Acta
    {
        return Acta::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->where('situacion', Acta::CERRADA)
            ->latest('id')
            ->first();
    }

    /**
     * ¿Se puede capturar hoy? Con un acta cerrada y sin corrección en curso, no:
     * cambiar una nota asentada exige un acta de corrección.
     */
    public function capturaAbierta(AsignaturaGrupo $asignaturaGrupo): bool
    {
        return $this->correccionAbierta($asignaturaGrupo) !== null
            || $this->actaCerrada($asignaturaGrupo) === null;
    }

    /**
     * Guarda las calificaciones capturadas de la hoja.
     *
     * Sólo pares que pertenezcan a ESTA materia —un id ajeno no debe colarse por
     * el payload— y sólo de los cortes que el calendario deja tocar hoy: la
     * ventana pudo cerrarse entre que se pintó la pantalla y se envió. Lo que sí
     * se puede se guarda; lo que no, se devuelve en `rechazados` con su motivo,
     * para no hacer perder la captura de las demás columnas por una cerrada.
     *
     * NULL no es cero: una celda vacía deja la calificación incompleta, no la
     * pondera como 0. `actualizarOReviver` revive la fila borrada (el 1062).
     *
     * @param  array<int, array{inscripcion_id: int|string, esquema_evaluacion_id: int|string, calificacion?: int|float|string|null}>  $calificaciones
     * @return array{guardadas: int, rechazados: array<int, string>}
     */
    public function guardar(AsignaturaGrupo $asignaturaGrupo, array $calificaciones, int $personaId): array
    {
        $inscripciones = Inscripcion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->pluck('id')
            ->flip();

        $componentes = EsquemaEvaluacion::query()
            ->where('plan_materia_id', $asignaturaGrupo->plan_materia_id)
            ->pluck('id')
            ->flip();

        $estadoDeCortes = $this->calendario->estadoPorParcial($asignaturaGrupo, $personaId);
        $parcialPorComponente = EsquemaEvaluacion::query()
            ->where('plan_materia_id', $asignaturaGrupo->plan_materia_id)
            ->pluck('parcial', 'id');

        $guardadas = 0;
        $rechazados = [];

        DB::transaction(function () use ($calificaciones, $inscripciones, $componentes, $personaId, $estadoDeCortes, $parcialPorComponente, &$guardadas, &$rechazados): void {
            foreach ($calificaciones as $fila) {
                if (! $inscripciones->has($fila['inscripcion_id']) || ! $componentes->has($fila['esquema_evaluacion_id'])) {
                    continue;
                }

                $parcial = $parcialPorComponente[$fila['esquema_evaluacion_id']] ?? null;
                $corte = $estadoDeCortes[$parcial === null ? '' : (string) $parcial] ?? ['abierto' => true, 'motivo' => null];

                if (! $corte['abierto']) {
                    $rechazados[$corte['motivo'] ?? 'Corte cerrado.'] = true;

                    continue;
                }

                CalificacionComponente::actualizarOReviver(
                    [
                        'inscripcion_id' => $fila['inscripcion_id'],
                        'esquema_evaluacion_id' => $fila['esquema_evaluacion_id'],
                    ],
                    [
                        'calificacion' => $fila['calificacion'] ?? null,
                        'capturado_por' => $personaId,
                        'capturado_en' => now(),
                    ],
                );

                $guardadas++;
            }
        });

        return ['guardadas' => $guardadas, 'rechazados' => array_keys($rechazados)];
    }
}
