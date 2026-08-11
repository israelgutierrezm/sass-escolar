<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use App\Support\Creditos;
use Illuminate\Support\Collection;

/**
 * El historial académico de una matrícula: sus renglones y sus totales.
 *
 * ── Por qué es un servicio y no vive en el controlador ─────────────────────
 * Lo miran DOS oficios por dos puertas distintas: control escolar desde el
 * expediente del alumno, y el propio alumno desde su portal. Es el mismo
 * historial académico y tiene que dar el mismo número en los dos sitios —si el
 * promedio que ve el alumno no es el que ve la ventanilla, alguien va a
 * reclamar con razón y nadie sabrá cuál de los dos está mal.
 *
 * Y no es una consulta: son tres decisiones de dominio que se pueden tomar de
 * varias maneras —qué renglones cuentan para los totales, qué se considera «en
 * curso» y cómo se promedia—. Copiadas en dos pantallas, divergen.
 */
class HistorialDelAlumno
{
    /**
     * Los renglones del historial académico: lo asentado y, al final, lo que está cursando.
     *
     * @return array<int, array<string, mixed>>
     */
    public function renglones(MatriculaOferta $matricula): array
    {
        $historial = $this->historial($matricula);

        return $historial
            ->map(fn (Historial $h) => [
                'id' => $h->id,
                'plan_materia_id' => $h->plan_materia_id,
                'clave_en_plan' => $h->planMateria?->clave_en_plan,
                'materia' => $h->planMateria?->asignatura?->nombre,
                'creditos' => $h->planMateria?->asignatura?->creditos,
                // El periodo (grado) de la materia en el plan: agrupa el historial académico.
                'periodo' => $h->planMateria?->periodo,
                'ciclo' => $h->ciclo?->clave,
                'calificacion' => $h->calificacion,
                'estatus' => $h->estatus?->nombre,
                'estatus_clave' => $h->estatus?->clave,
                'tipo_evaluacion' => $h->tipoEvaluacion?->nombre,
                'acta_folio' => $h->acta_folio,
                'observacion' => $h->observacion?->nombre,
                // Estatus académico oficial SEP (equivalencia, revalidación…).
                'observacion_asignatura' => $h->observacionAsignatura?->nombre,
                // Renglón cargado a mano (sin acta): se puede retirar desde el
                // expediente. El alumno no lo retira, pero el dato es el mismo.
                'manual' => $h->acta_id === null,
                'en_curso' => false,
            ])
            ->concat($this->materiasEnCurso($matricula, $historial))
            ->values()
            ->all();
    }

    /**
     * Los totales.
     *
     * Se calculan sobre el MEJOR intento por materia, no por renglón: una
     * materia aprobada a título después de tronar el ordinario cuenta una vez, y
     * como aprobada. El historial académico sí enseña los dos renglones —es historia y no se
     * borra—, pero sumarlos daría un promedio que castiga dos veces el mismo
     * tropiezo y unos créditos que no existen.
     *
     * @return array<string, mixed>
     */
    public function resumen(MatriculaOferta $matricula): array
    {
        $mejores = $this->mejoresIntentos($this->historial($matricula));
        $aprobadas = $mejores->filter(fn (Historial $h) => $h->estatus?->clave === 'aprobada');

        /*
         * Cuántas materias exige el plan para darse por completo.
         *
         * Se mide por CONTEO de materias distintas aprobadas y no por créditos
         * ni promedio: los créditos pueden alcanzar faltando una materia, y
         * entonces se le diría a alguien que ya puede certificarse cuando no.
         * Si el plan no fija el mínimo, se cae al número de materias de su malla.
         */
        $metaMaterias = (int) ($matricula->oferta?->plan?->minimo_asignaturas
            ?: PlanMateria::query()->where('plan_id', $matricula->oferta?->plan_id)->count());

        return [
            'materias_cursadas' => $mejores->count(),
            'aprobadas' => $aprobadas->count(),
            'reprobadas' => $mejores->filter(fn (Historial $h) => $h->estatus?->clave === 'reprobada')->count(),
            // La misma suma que el portal del padre y el certificado: tres
            // copias con dos precisiones distintas daban tres cifras.
            'creditos' => Creditos::sumar($aprobadas),
            'promedio' => $this->promedio($mejores, $matricula->oferta?->plan),
            'creditos_del_plan' => $matricula->oferta?->plan?->total_creditos,
            'materias_para_completar' => $metaMaterias,
            // Cerró el plan: aprobó al menos las materias que exige.
            'disponible_certificar' => $metaMaterias > 0 && $aprobadas->count() >= $metaMaterias,
            // Tiene avance pero NO cerró el plan: le toca certificado parcial.
            'disponible_parcial' => $metaMaterias > 0 && $aprobadas->count() > 0 && $aprobadas->count() < $metaMaterias,
        ];
    }

    /**
     * Lo asentado, en el orden en que se cursó.
     *
     * @return Collection<int, Historial>
     */
    public function historial(MatriculaOferta $matricula): Collection
    {
        return Historial::query()
            ->with([
                'planMateria.asignatura:id,nombre,creditos',
                'ciclo:id,clave',
                'estatus:id,clave,nombre',
                'tipoEvaluacion:id,nombre',
                'observacion:id,nombre',
                'observacionAsignatura:id,nombre',
            ])
            ->where('matricula_oferta_id', $matricula->id)
            ->get()
            ->sortBy([['ciclo.clave', 'asc'], ['planMateria.clave_en_plan', 'asc']])
            ->values();
    }

    /**
     * Un renglón por materia: el intento con la calificación más alta.
     *
     * @param  Collection<int, Historial>  $historial
     * @return Collection<int, Historial>
     */
    public function mejoresIntentos(Collection $historial): Collection
    {
        return $historial
            ->filter(fn (Historial $h) => $h->plan_materia_id !== null)
            ->groupBy('plan_materia_id')
            ->map(fn ($intentos) => $intentos->sortByDesc(fn (Historial $h) => (float) ($h->calificacion ?? -1))->first())
            ->values();
    }

    /**
     * Lo que está cursando ahora y todavía no tiene acta.
     *
     * @param  Collection<int, Historial>  $historial
     * @return Collection<int, array<string, mixed>>
     */
    public function materiasEnCurso(MatriculaOferta $matricula, Collection $historial): Collection
    {
        // Lo ya asentado, por materia y ciclo: es lo que NO se vuelve a mostrar.
        $asentadas = $historial
            ->map(fn (Historial $h) => $h->plan_materia_id.'-'.$h->ciclo_id)
            ->flip();

        return Inscripcion::query()
            ->with([
                'asignaturaGrupo.planMateria.asignatura:id,nombre,creditos',
                'ciclo:id,clave',
                'situacion:id,clave,nombre',
            ])
            ->where('matricula_oferta_id', $matricula->id)
            ->get()
            ->reject(fn (Inscripcion $i) => $i->situacion?->clave === 'baja')
            ->reject(fn (Inscripcion $i) => $asentadas->has(
                $i->asignaturaGrupo?->plan_materia_id.'-'.$i->ciclo_id,
            ))
            ->map(fn (Inscripcion $i) => [
                // Prefijo para no chocar con los ids de `historial`: en el
                // frontend los dos viven en la misma lista.
                'id' => 'curso-'.$i->id,
                'plan_materia_id' => $i->asignaturaGrupo?->plan_materia_id,
                'clave_en_plan' => $i->asignaturaGrupo?->planMateria?->clave_en_plan,
                'materia' => $i->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                'creditos' => $i->asignaturaGrupo?->planMateria?->asignatura?->creditos,
                'periodo' => $i->asignaturaGrupo?->planMateria?->periodo,
                'ciclo' => $i->ciclo?->clave,
                // Sin calificación: la que lleve acumulada es provisional y vive
                // en «Carga por ciclo». El historial académico sólo dice lo definitivo.
                'calificacion' => null,
                'estatus' => 'En curso',
                'estatus_clave' => 'en_curso',
                'tipo_evaluacion' => null,
                'acta_folio' => null,
                'observacion' => null,
                'observacion_asignatura' => null,
                // No se retira desde aquí: se da de baja en Inscripciones.
                'manual' => false,
                'en_curso' => true,
            ])
            ->values();
    }

    /**
     * El promedio, con los decimales que fije el plan.
     *
     * Sólo sobre lo que tiene calificación: un NULL es una materia sin cerrar,
     * no un cero. Promediarlo como cero hundiría el promedio de quien va al
     * corriente por el simple hecho de estar cursando.
     *
     * @param  Collection<int, Historial>  $intentos
     */
    public function promedio(Collection $intentos, ?PlanEstudio $plan): ?float
    {
        $conCalificacion = $intentos->filter(fn (Historial $h) => $h->calificacion !== null);

        if ($conCalificacion->isEmpty()) {
            return null;
        }

        return PlanEstudio::redondearCon(
            $plan,
            (float) $conCalificacion->avg(fn (Historial $h) => (float) $h->calificacion),
        );
    }
}
