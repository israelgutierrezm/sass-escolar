<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Emision\Certificacion;

/**
 * Responde dos preguntas sobre una matrícula-carrera: ¿ya cerró su plan (está
 * lista para certificar)? y ¿tiene una certificación (emitida o en un lote)?
 *
 * Es la fuente única de la regla de elegibilidad, para que el buscador de
 * candidatos del lote y el panel del expediente digan lo mismo.
 */
class EstadoCertificacion
{
    /** @var array<int, int> caché de meta de materias por plan dentro del request */
    private array $metaPorPlan = [];

    /**
     * Cuántas materias distintas exige el plan para cerrarse. Si no fija
     * `minimo_asignaturas`, se cae al número de materias de su malla.
     */
    public function metaMaterias(?PlanEstudio $plan): int
    {
        if ($plan === null) {
            return 0;
        }

        return $this->metaPorPlan[$plan->id] ??= (int) ($plan->minimo_asignaturas
            ?: PlanMateria::query()->where('plan_id', $plan->id)->count());
    }

    /** Materias distintas aprobadas (basta un intento aprobado por materia). */
    public function aprobadasDistintas(int $matriculaId): int
    {
        return Historial::query()
            ->where('matricula_oferta_id', $matriculaId)
            ->whereNotNull('plan_materia_id')
            ->whereHas('estatus', fn ($q) => $q->where('clave', 'aprobada'))
            ->distinct()
            ->count('plan_materia_id');
    }

    /*
     * ── Qué emite la carrera ───────────────────────────────────────────────
     *
     * Un diplomado o un curso de educación continua vive en el mismo catálogo de
     * carreras y no tiene RVOE que respalde papel oficial. Cerrar su plan no lo
     * vuelve certificable, y ofrecerlo entre los candidatos de un lote es
     * prometer un trámite que la escuela no puede cumplir.
     *
     * Son dos permisos separados a propósito: hay programas que dan constancia
     * con certificado pero no llegan a título.
     *
     * Ante la duda —carrera no cargada, dato ausente— se responde que sí: es lo
     * que se asumía de todas antes de que el campo existiera, y equivocarse por
     * exceso deja al alumno visible para que una persona decida, mientras que
     * equivocarse por defecto lo desaparece sin que nadie se entere.
     */

    public function emiteCertificado(MatriculaOferta $matricula): bool
    {
        return (bool) ($matricula->oferta?->carrera?->emite_certificado ?? true);
    }

    public function emiteTitulo(MatriculaOferta $matricula): bool
    {
        return (bool) ($matricula->oferta?->carrera?->emite_titulo ?? true);
    }

    /**
     * Cerró su plan: aprobó al menos las materias que exige.
     *
     * Es el requisito ACADÉMICO y nada más. Lo que la carrera llegue a emitir se
     * pregunta aparte, porque certificado y título se conceden por separado y
     * este mismo método responde a los dos.
     */
    public function disponible(MatriculaOferta $matricula): bool
    {
        $meta = $this->metaMaterias($matricula->oferta?->plan);

        return $meta > 0 && $this->aprobadasDistintas($matricula->id) >= $meta;
    }

    /**
     * La certificación que «ocupa» a la matrícula: la emitida, o la pendiente en
     * un lote aún sin firmar. Un renglón en `error` no ocupa (se puede reintentar
     * metiéndola a otro lote).
     */
    public function certificacionVigente(int $matriculaId): ?Certificacion
    {
        return Certificacion::query()
            ->with('lote:id,folio,estado')
            ->where('matricula_oferta_id', $matriculaId)
            ->where('estado', '!=', Certificacion::ERROR)
            ->latest('id')
            ->first();
    }

    /**
     * Elegible para un certificado PARCIAL: tiene avance (al menos una materia
     * aprobada) pero AÚN NO cerró su plan —si ya lo cerró, le toca el total—.
     */
    public function disponibleParcial(MatriculaOferta $matricula): bool
    {
        return ! $this->disponible($matricula)
            && $this->aprobadasDistintas($matricula->id) > 0;
    }

    /**
     * Se puede agregar a un lote del tipo dado (total/parcial) y no está ya en
     * otro lote. Total: cerró su plan. Parcial: tiene avance sin cerrarlo.
     */
    public function elegibleParaLote(MatriculaOferta $matricula, string $tipo = 'total'): bool
    {
        if (! $this->emiteCertificado($matricula)) {
            return false;
        }

        if ($this->certificacionVigente($matricula->id) !== null) {
            return false;
        }

        return $tipo === 'parcial'
            ? $this->disponibleParcial($matricula)
            : $this->disponible($matricula);
    }
}
