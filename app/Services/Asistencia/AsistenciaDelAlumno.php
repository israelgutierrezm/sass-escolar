<?php

declare(strict_types=1);

namespace App\Services\Asistencia;

use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Support\Collection;

/**
 * Cuánto ha asistido un alumno a una materia.
 *
 * ── Por qué es un servicio y no una consulta ───────────────────────────────
 * Porque no es una consulta: son tres decisiones de dominio que se pueden tomar
 * de varias maneras —qué cuenta como presencia, cuál es el denominador y qué
 * pasa cuando no hay datos— y este proyecto ya tiene la lección escrita con el
 * promedio, que llegó a calcularse de tres formas y a dar tres números.
 *
 * ── EL HALLAZGO: ya se calculaba de dos maneras, y dan números distintos ───
 * Medido en el código el 2026-09-04, antes de escribir esto:
 *
 *  - `AsistenciaPorMateria` (el reporte): `(presentes + justificadas) / sesiones`.
 *    La justificada cuenta; el RETARDO no.
 *  - `DocenciaController` (la pantalla del docente): `(presentes + retardos) / total`.
 *    El retardo cuenta; la JUSTIFICADA no.
 *
 * Para un alumno con 10 sesiones, 6 presentes, 2 justificadas y 2 retardos, el
 * reporte dice 80 % y la pantalla del docente dice 80 % — coinciden por
 * casualidad. Con 6 presentes, 3 justificadas y 1 retardo, el reporte dice 90 %
 * y la pantalla 70 %. Es el mismo alumno.
 *
 * **Este servicio NO cambia ninguna de las dos.** Cambiar un número que una
 * escuela ya lee es una decisión suya, no un refactor; queda anotado para que
 * se decida a la vista de las tres cifras. Lo que sí hace es fijar la
 * definición que usa el módulo de permanencia, y decir cuál es.
 *
 * ── La que usa este módulo: TODO LO QUE NO ES FALTA ────────────────────────
 * `(sesiones - faltas) / sesiones`. Es la que corresponde a la pregunta que se
 * está haciendo: el derecho a examen se pierde por FALTAS, y una justificada se
 * justifica precisamente para que no cuente. El retardo entra porque llegar
 * tarde es haber ido; que tres retardos hagan una falta es una regla que
 * ninguna escuela tiene configurada aquí todavía, y contarlo como ausencia sin
 * que nadie lo haya decidido sería inventarla.
 *
 * ── El denominador son las sesiones REGISTRADAS, no el calendario ──────────
 * Y hay que decirlo en cada pantalla que lo use, porque es la diferencia entre
 * un número útil y uno que engaña: si un docente pasó lista tres veces en el
 * semestre, «100 %» significa que fue a esas tres, no que no ha faltado. Por
 * eso `sesiones` viaja siempre al lado del porcentaje, y por eso el motor de
 * alertas exige una cobertura mínima antes de atreverse a opinar.
 *
 * ── Sin sesiones el porcentaje es NULL, no cero ni cien ────────────────────
 * Los dos mentirían: cero diría que no ha ido nunca y cien que no ha faltado.
 * Es el mismo criterio que `NULL no es cero` en la captura de calificaciones.
 */
class AsistenciaDelAlumno
{
    /**
     * Los cuatro conteos de una inscripción, en una ventana opcional.
     *
     * @return array{sesiones: int, presentes: int, faltas: int, justificadas: int, retardos: int}
     */
    public function conteos(int $inscripcionId, ?string $desde = null, ?string $hasta = null): array
    {
        $filas = AsistenciaClase::query()
            ->where('inscripcion_id', $inscripcionId)
            ->when($desde !== null, fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->whereDate('fecha', '<=', $hasta))
            ->selectRaw('estatus, count(*) as c')
            ->groupBy('estatus')
            ->pluck('c', 'estatus');

        return $this->desdeConteos($filas);
    }

    /**
     * Lo mismo para VARIAS inscripciones de una vez.
     *
     * Existe porque el motor evalúa por lotes: preguntar inscripción por
     * inscripción es la consulta N+1 que este proyecto persigue desde hace
     * meses, y aquí serían seis materias por alumno por cada corrida.
     *
     * @param  array<int, int>  $inscripciones
     * @return Collection<int, array{sesiones: int, presentes: int, faltas: int, justificadas: int, retardos: int}>
     */
    public function conteosDe(array $inscripciones, ?string $desde = null, ?string $hasta = null): Collection
    {
        if ($inscripciones === []) {
            return collect();
        }

        $filas = AsistenciaClase::query()
            ->whereIn('inscripcion_id', $inscripciones)
            ->when($desde !== null, fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->whereDate('fecha', '<=', $hasta))
            ->selectRaw('inscripcion_id, estatus, count(*) as c')
            ->groupBy('inscripcion_id', 'estatus')
            ->get();

        return collect($inscripciones)
            ->mapWithKeys(fn (int $id) => [$id => $this->desdeConteos(
                $filas->where('inscripcion_id', $id)->pluck('c', 'estatus'),
            )]);
    }

    /**
     * El porcentaje que usa el módulo de permanencia: todo lo que no es falta.
     *
     * NULL sin sesiones registradas. Ver el docblock de la clase para por qué
     * ésta y no las otras dos que ya existen en el sistema.
     *
     * @param  array{sesiones: int, faltas: int, ...}  $conteos
     */
    public function porcentaje(array $conteos): ?float
    {
        if ($conteos['sesiones'] === 0) {
            return null;
        }

        return round(($conteos['sesiones'] - $conteos['faltas']) * 100 / $conteos['sesiones'], 1);
    }

    /**
     * Cuántas FALTAS seguidas lleva, mirando desde la sesión más reciente.
     *
     * ── Qué corta la racha, y por qué ──────────────────────────────────────
     * Cualquier sesión que no sea falta: una presencia, un retardo y también una
     * JUSTIFICADA. Lo tercero es lo que importa: si una justificada no cortara,
     * un alumno con permiso médico de tres días saldría con «tres faltas
     * seguidas» el lunes siguiente, y eso es exactamente lo contrario de lo que
     * significa justificar.
     *
     * Se cuenta desde el final y se para en la primera que no sea falta. Una
     * sesión sin registrar no existe: no corta ni suma, porque no sabemos qué
     * pasó ese día.
     */
    public function faltasConsecutivas(int $inscripcionId, ?string $desde = null): int
    {
        $sesiones = AsistenciaClase::query()
            ->where('inscripcion_id', $inscripcionId)
            ->when($desde !== null, fn ($q) => $q->whereDate('fecha', '>=', $desde))
            ->orderByDesc('fecha')
            // Desempate estable: dos sesiones el mismo día —teoría y práctica—
            // existen, y sin él la racha cambiaría de una corrida a otra.
            ->orderByDesc('id')
            ->pluck('estatus');

        $seguidas = 0;

        foreach ($sesiones as $estatus) {
            if ($estatus !== AsistenciaClase::FALTA) {
                break;
            }

            $seguidas++;
        }

        return $seguidas;
    }

    /**
     * Las inscripciones VIVAS de una matrícula: sobre las que se puede opinar.
     *
     * A quien se dio de baja de una materia no se le puede pasar lista
     * —`DocenciaController` lo saca de la lista del docente—, así que contarlo
     * lo dejaría para siempre en la cola con un porcentaje que nadie puede
     * corregir. Es el criterio que ya usan `CargaAcademica`,
     * `Grupo::inscritosDelGrupo()` y la fuente de reporte: la clave de la
     * situación distinta de `baja`, tolerando el NULL.
     *
     * @return Collection<int, Inscripcion>
     */
    public function inscripcionesVivas(int $matriculaOfertaId, ?int $cicloId = null): Collection
    {
        return Inscripcion::query()
            ->where('matricula_oferta_id', $matriculaOfertaId)
            ->when($cicloId !== null, fn ($q) => $q->where('ciclo_id', $cicloId))
            ->with(['situacion:id,clave', 'asignaturaGrupo:id,plan_materia_id,grupo_id'])
            ->get()
            ->reject(fn (Inscripcion $i) => $i->situacion?->clave === 'baja')
            ->values();
    }

    /**
     * @param  Collection<string, int>  $porEstatus
     * @return array{sesiones: int, presentes: int, faltas: int, justificadas: int, retardos: int}
     */
    private function desdeConteos(Collection $porEstatus): array
    {
        $presentes = (int) $porEstatus->get(AsistenciaClase::PRESENTE, 0);
        $faltas = (int) $porEstatus->get(AsistenciaClase::FALTA, 0);
        $justificadas = (int) $porEstatus->get(AsistenciaClase::JUSTIFICADA, 0);
        $retardos = (int) $porEstatus->get(AsistenciaClase::RETARDO, 0);

        return [
            /*
             * El total sale de SUMAR los cuatro y no de un `count(*)` aparte:
             * así el denominador no puede decir algo distinto de lo que dicen
             * sus partes. Con dos consultas, un estatus que la escuela agregue
             * mañana entraría al total y no a ninguna columna, y el porcentaje
             * bajaría sin que nada lo explicara.
             */
            'sesiones' => $presentes + $faltas + $justificadas + $retardos,
            'presentes' => $presentes,
            'faltas' => $faltas,
            'justificadas' => $justificadas,
            'retardos' => $retardos,
        ];
    }
}
