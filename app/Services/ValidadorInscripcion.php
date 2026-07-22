<?php

declare(strict_types=1);

namespace App\Services;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\Seriacion;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Equivalencia;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Finanzas\BitacoraSituacionFinanciera;

/**
 * Reglas para inscribir a un alumno en una materia-grupo.
 *
 * Son las cinco validaciones que enumera la spec, más la de duplicidad. Se
 * devuelven TODAS las que fallan, no la primera: quien inscribe necesita saber
 * todo lo que falta de una vez, no descubrirlo error por error.
 *
 * El mismo validador sirve para la inscripción autogestiva del alumno y para
 * la administrativa; lo que cambia es quién la ejecuta, no las reglas.
 */
class ValidadorInscripcion
{
    public function __construct(private readonly Ajustes $ajustes) {}

    /**
     * Motivos por los que NO se puede inscribir. Vacío = adelante.
     *
     * @return array<int, string>
     */
    public function impedimentos(MatriculaOferta $matricula, AsignaturaGrupo $materiaGrupo): array
    {
        $matricula->loadMissing('oferta');
        $materiaGrupo->loadMissing(['planMateria.asignatura', 'grupo.ciclo']);

        return array_values(array_filter([
            $this->yaInscrito($matricula, $materiaGrupo),
            $this->materiaDeOtroPlan($matricula, $materiaGrupo),
            $this->ventanaCerrada($materiaGrupo),
            $this->cupoLleno($materiaGrupo),
            ...$this->seriacionPendiente($matricula, $materiaGrupo),
            $this->choqueDeHorario($matricula, $materiaGrupo),
            // Reglas configurables de la escuela. Solo estorban si están
            // puestas en «bloquear»; si están en «advertir» salen por
            // `advertencias()`.
            $this->limiteConfigurable($matricula, $materiaGrupo, true),
            $this->adeudoBloqueante($matricula),
        ]));
    }

    /**
     * Lo que hay que saber pero NO impide inscribir.
     *
     * Existe porque una regla de la escuela tiene dos formas legítimas de
     * aplicarse: hay quien no permite el tercer recursamiento y quien sí, con
     * el visto bueno de dirección. Forzar todo a bloqueo obligaría a apagar la
     * regla para poder excepcionarla, y entonces nadie se enteraría.
     *
     * @return array<int, string>
     */
    public function advertencias(MatriculaOferta $matricula, AsignaturaGrupo $materiaGrupo): array
    {
        $matricula->loadMissing('oferta');
        $materiaGrupo->loadMissing(['planMateria.asignatura', 'grupo.ciclo']);

        return array_values(array_filter([
            $this->limiteConfigurable($matricula, $materiaGrupo, false),
        ]));
    }

    public function puedeInscribir(MatriculaOferta $matricula, AsignaturaGrupo $materiaGrupo): bool
    {
        return $this->impedimentos($matricula, $materiaGrupo) === [];
    }

    /**
     * Los dos límites que se comprueban al inscribir: recursamientos de la
     * misma materia y carga del ciclo.
     *
     * `$bloqueantes` decide de qué lado sale cada uno según su acción
     * configurada, así que la regla se evalúa UNA vez y se reparte, en vez de
     * duplicar el conteo en dos métodos que podrían divergir.
     */
    private function limiteConfigurable(
        MatriculaOferta $matricula,
        AsignaturaGrupo $materiaGrupo,
        bool $bloqueantes,
    ): ?string {
        $avisos = [];

        // Recursamientos de ESTA materia del plan.
        if ($this->ajustes->hayLimite(CatalogoAjustes::MAX_RECURSAMIENTOS)
            && $this->ajustes->bloquea(CatalogoAjustes::ACCION_RECURSAMIENTOS) === $bloqueantes) {
            $limite = $this->ajustes->entero(CatalogoAjustes::MAX_RECURSAMIENTOS);

            $cursadas = Inscripcion::query()
                ->where('matricula_oferta_id', $matricula->id)
                ->whereHas('asignaturaGrupo', fn ($q) => $q->where('plan_materia_id', $materiaGrupo->plan_materia_id))
                ->count();

            // La primera vez es cursar, no recursar: el límite cuenta los
            // INTENTOS ADICIONALES, que es como lo dice un reglamento.
            if ($cursadas > $limite) {
                $materia = $materiaGrupo->planMateria?->asignatura?->nombre ?? 'esta materia';
                $avisos[] = "Ya cursó {$materia} {$cursadas} veces; el límite de recursamientos es {$limite}.";
            }
        }

        // Carga del ciclo.
        if ($this->ajustes->hayLimite(CatalogoAjustes::MAX_MATERIAS_CICLO)
            && $this->ajustes->bloquea(CatalogoAjustes::ACCION_MATERIAS_CICLO) === $bloqueantes) {
            $limite = $this->ajustes->entero(CatalogoAjustes::MAX_MATERIAS_CICLO);
            $cicloId = $materiaGrupo->grupo?->ciclo_id;

            if ($cicloId !== null) {
                $llevadas = Inscripcion::query()
                    ->where('matricula_oferta_id', $matricula->id)
                    ->where('ciclo_id', $cicloId)
                    ->count();

                if ($llevadas >= $limite) {
                    $avisos[] = "Ya lleva {$llevadas} materias este ciclo; el máximo configurado es {$limite}.";
                }
            }
        }

        return $avisos === [] ? null : implode(' ', $avisos);
    }

    /**
     * El adeudo impide inscribirse, si la escuela lo configuró así.
     *
     * QUIÉN queda bloqueado no lo decide este interruptor sino el catálogo de
     * `situaciones_pago`: el ajuste solo dice si esa bandera, que hasta ahora
     * solo informaba, de verdad detiene el trámite.
     */
    private function adeudoBloqueante(MatriculaOferta $matricula): ?string
    {
        if (! $this->ajustes->bool(CatalogoAjustes::BLOQUEO_FINANCIERO)) {
            return null;
        }

        $vigente = BitacoraSituacionFinanciera::vigenteDe($matricula->id);

        if ($vigente?->situacion?->bloquea !== true) {
            return null;
        }

        return 'Su situación financiera es «'.$vigente->situacion->nombre.'», que impide inscribir.';
    }

    private function yaInscrito(MatriculaOferta $matricula, AsignaturaGrupo $materiaGrupo): ?string
    {
        $existe = Inscripcion::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('asignatura_grupo_id', $materiaGrupo->id)
            ->exists();

        return $existe ? 'El alumno ya está inscrito en esta materia.' : null;
    }

    /**
     * La materia debe pertenecer al plan en el que el alumno está matriculado:
     * su acta y su kárdex se llevan contra ese plan.
     */
    private function materiaDeOtroPlan(MatriculaOferta $matricula, AsignaturaGrupo $materiaGrupo): ?string
    {
        $planDelAlumno = $matricula->oferta?->plan_id;
        $planDeLaMateria = $materiaGrupo->planMateria?->plan_id;

        if ($planDelAlumno === null || $planDeLaMateria === null || $planDelAlumno === $planDeLaMateria) {
            return null;
        }

        return 'La materia pertenece a otro plan de estudios.';
    }

    private function ventanaCerrada(AsignaturaGrupo $materiaGrupo): ?string
    {
        $ciclo = $materiaGrupo->grupo?->ciclo;

        if ($ciclo === null) {
            return 'La materia no está ligada a un ciclo.';
        }

        return $ciclo->inscripcionAbierta()
            ? null
            : 'La ventana de inscripción del ciclo está cerrada.';
    }

    /**
     * El cupo es del grupo: es el aula y el docente los que se saturan, no la
     * materia por separado.
     */
    private function cupoLleno(AsignaturaGrupo $materiaGrupo): ?string
    {
        $cupo = $materiaGrupo->grupo?->cupo;

        if ($cupo === null) {
            return null;
        }

        $inscritos = Inscripcion::query()->where('asignatura_grupo_id', $materiaGrupo->id)->count();

        return $inscritos >= $cupo
            ? "El grupo alcanzó su cupo ({$cupo})."
            : null;
    }

    /**
     * Recorre los prerrequisitos de la materia-en-plan y verifica cada uno
     * contra el historial del alumno. Las equivalencias revalidadas cuentan
     * como materia cubierta.
     *
     * @return array<int, string>
     */
    private function seriacionPendiente(MatriculaOferta $matricula, AsignaturaGrupo $materiaGrupo): array
    {
        $planMateria = $materiaGrupo->planMateria;

        if ($planMateria === null) {
            return [];
        }

        $requisitos = Seriacion::query()
            ->with('requiere.asignatura')
            ->where('plan_materia_id', $planMateria->id)
            ->get();

        $faltantes = [];

        foreach ($requisitos as $requisito) {
            if ($requisito->requiere_plan_materia_id !== null) {
                $mensaje = $this->requisitoDeMateria($matricula, $requisito);
            } else {
                $mensaje = $this->requisitoDeCreditos($matricula, $requisito);
            }

            if ($mensaje !== null) {
                $faltantes[] = $mensaje;
            }
        }

        return $faltantes;
    }

    private function requisitoDeMateria(MatriculaOferta $matricula, Seriacion $requisito): ?string
    {
        $historial = Historial::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('plan_materia_id', $requisito->requiere_plan_materia_id);

        // "Aprobada" exige que el estatus lo sea; "cursada" basta con que exista
        // registro, sin importar el resultado.
        if ($requisito->tipo === 'aprobada') {
            $historial->whereHas('estatus', fn ($q) => $q->where('clave', 'aprobada'));
        }

        if ($historial->exists()) {
            return null;
        }

        // Una materia revalidada de otra institución cubre el requisito.
        $revalidada = Equivalencia::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('plan_materia_id', $requisito->requiere_plan_materia_id)
            ->exists();

        if ($revalidada) {
            return null;
        }

        $nombre = $requisito->requiere?->asignatura?->nombre
            ?? $requisito->requiere?->clave_en_plan
            ?? 'una materia previa';

        return $requisito->tipo === 'aprobada'
            ? "Falta aprobar {$nombre}."
            : "Falta haber cursado {$nombre}.";
    }

    private function requisitoDeCreditos(MatriculaOferta $matricula, Seriacion $requisito): ?string
    {
        $minimo = (float) ($requisito->minimo_creditos ?? 0);

        if ($minimo <= 0) {
            return null;
        }

        $acumulados = $this->creditosAprobados($matricula);

        return $acumulados >= $minimo
            ? null
            : sprintf('Requiere %s créditos acumulados y lleva %s.', $minimo, $acumulados);
    }

    /**
     * Créditos de las materias aprobadas: los del plan si se sobreescribieron,
     * o los del catálogo.
     */
    private function creditosAprobados(MatriculaOferta $matricula): float
    {
        $planMateriaIds = Historial::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->whereHas('estatus', fn ($q) => $q->where('clave', 'aprobada'))
            ->pluck('plan_materia_id');

        return (float) PlanMateria::query()
            ->with('asignatura:id,creditos')
            ->whereIn('id', $planMateriaIds)
            ->get()
            ->sum(fn (PlanMateria $materia) => $materia->creditos_en_plan ?? $materia->asignatura?->creditos ?? 0);
    }

    /**
     * Choque de horario contra lo que el alumno ya lleva en el mismo ciclo. Sin
     * horarios cargados no se puede afirmar que haya choque, así que no bloquea.
     */
    private function choqueDeHorario(MatriculaOferta $matricula, AsignaturaGrupo $materiaGrupo): ?string
    {
        $materiaGrupo->loadMissing('horarios');

        if ($materiaGrupo->horarios->isEmpty()) {
            return null;
        }

        $cicloId = $materiaGrupo->grupo?->ciclo_id;

        $inscritas = AsignaturaGrupo::query()
            ->with(['horarios', 'planMateria.asignatura:id,nombre'])
            ->whereIn(
                'id',
                Inscripcion::query()
                    ->where('matricula_oferta_id', $matricula->id)
                    ->where('ciclo_id', $cicloId)
                    ->pluck('asignatura_grupo_id')
            )
            ->get();

        foreach ($inscritas as $otra) {
            foreach ($otra->horarios as $horarioOtra) {
                foreach ($materiaGrupo->horarios as $horario) {
                    if ($horario->chocaCon($horarioOtra)) {
                        $nombre = $otra->planMateria?->asignatura?->nombre ?? 'otra materia';

                        return "Choca de horario con {$nombre}.";
                    }
                }
            }
        }

        return null;
    }
}
