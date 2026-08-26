<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\Emision\Certificacion;
use Illuminate\Database\Query\Builder as ConsultaCruda;
use Illuminate\Support\Facades\DB;

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

    /**
     * ¿La carrera de esta matrícula expide documentos oficiales?
     *
     * Un diplomado o un curso de educación continua vive en el mismo catálogo de
     * carreras y no tiene RVOE que respalde papel oficial. Cerrar su plan no lo
     * vuelve certificable, y ofrecerlo entre los candidatos de un lote es
     * prometer un trámite que la escuela no puede cumplir.
     *
     * Certificado y título son UN permiso, no dos: donde hay uno hay el otro
     * —el certificado acredita las materias y el título haberla terminado— y
     * separarlos sólo permitía media configuración.
     *
     * Ante la duda —carrera no cargada, dato ausente— se responde que sí: es lo
     * que se asumía de todas antes de que el campo existiera, y equivocarse por
     * exceso deja al alumno visible para que una persona decida, mientras que
     * equivocarse por defecto lo desaparece sin que nadie se entere.
     */
    public function emiteDocumentos(MatriculaOferta $matricula): bool
    {
        return (bool) ($matricula->oferta?->carrera?->emite_documentos_oficiales ?? true);
    }

    /**
     * Cerró su plan: aprobó al menos las materias que exige.
     *
     * Es el requisito ACADÉMICO y nada más: si la carrera expide documentos se
     * pregunta aparte. Lo consultan tanto certificación como titulación, así que
     * mezclarle esa condición aquí escondería el motivo real de un descarte.
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

    /*
    |--------------------------------------------------------------------------
    | Las MISMAS reglas, en forma de SQL
    |--------------------------------------------------------------------------
    |
    | Los métodos de arriba contestan por UNA matrícula y consultan por fila.
    | Está bien en una ficha; en un reporte no: medido contra el demo, resolver
    | la elegibilidad de las 32 matrículas cuesta **64 consultas** —dos por
    | fila—, y sobre tres mil son seis mil por cada pintado de pantalla Y por
    | cada lote de una exportación.
    |
    | Estas versiones dicen LO MISMO en una sola consulta. Viven aquí, pegadas a
    | las de arriba y no en la fuente del reporte, por la razón de siempre: dos
    | declaraciones de «cerró su plan» en dos archivos divergen, y el día que no
    | coincidan nadie sabrá cuál creer. Lo fija
    | `scripts/prueba-reportes-certificacion.php`, que compara las dos formas
    | matrícula por matrícula: si alguien toca una y no la otra, la suite se
    | pone roja.
    */

    /**
     * Materias distintas aprobadas POR MATRÍCULA, agrupado.
     *
     * Mismo criterio que {@see aprobadasDistintas()}: un intento aprobado basta,
     * y se cuentan materias del PLAN distintas —no renglones—, así que un
     * recursamiento aprobado dos veces cuenta una.
     *
     * Va agrupada y no correlacionada para poder unirla como tabla derivada: una
     * columna que salga de un `selectSub` es un alias, y MySQL no admite un
     * alias en el `WHERE` del recorrido por lotes.
     */
    public function aprobadasPorMatricula(): ConsultaCruda
    {
        return DB::table('historial as h')
            ->join('estatus_historial as eh', 'eh.id', '=', 'h.estatus_id')
            ->whereNull('h.deleted_at')
            ->whereNotNull('h.plan_materia_id')
            ->where('eh.clave', 'aprobada')
            ->select('h.matricula_oferta_id')
            ->selectRaw('count(distinct h.plan_materia_id) as aprobadas')
            ->groupBy('h.matricula_oferta_id');
    }

    /**
     * La meta de materias POR PLAN, agrupado.
     *
     * `nullif(minimo_asignaturas, 0)` es lo que traduce el `?:` de
     * {@see metaMaterias()}: en PHP el cero es falso y cae al conteo de la
     * malla, y sin el `nullif` un plan con la meta en 0 se quedaría en 0 y
     * NADIE de ese plan podría certificarse — el fallo silencioso peor, porque
     * el reporte saldría vacío y parecería que nadie ha terminado.
     */
    public function metaPorPlanConsulta(): ConsultaCruda
    {
        return DB::table('planes_estudio as p')
            ->whereNull('p.deleted_at')
            ->select('p.id as plan_id')
            ->selectRaw('coalesce(nullif(p.minimo_asignaturas, 0), (
                select count(*) from plan_materias pm
                where pm.plan_id = p.id and pm.deleted_at is null
            )) as meta');
    }

    /**
     * Las matrículas que YA están ocupadas por una certificación.
     *
     * Mismo criterio que {@see certificacionVigente()}: cuenta la emitida y la
     * pendiente en un lote sin firmar, y NO la que quedó en `error` —ésa se
     * puede reintentar metiéndola a otro lote—.
     */
    public function ocupadasPorCertificacion(): ConsultaCruda
    {
        return DB::table('certificaciones as c')
            ->whereNull('c.deleted_at')
            ->where('c.estado', '!=', Certificacion::ERROR)
            ->select('c.matricula_oferta_id')
            ->selectRaw('max(c.id) as certificacion_id')
            ->groupBy('c.matricula_oferta_id');
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
        if (! $this->emiteDocumentos($matricula)) {
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
