<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionAsignaturaGrupo;
use App\Services\AsentadorActa;
use App\Services\CalculadoraCalificacion;
use App\Services\Lms\CopiadorDeCurso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apertura de materias dentro de un grupo y asignación de sus docentes.
 *
 * Abrir una materia es lo que la vuelve inscribible: hasta que existe una
 * `asignatura_grupo`, la materia solo es parte del plan, no algo que se pueda
 * cursar este ciclo.
 */
class AsignaturaGrupoController extends Controller
{
    public function __construct(private readonly CopiadorDeCurso $copiador) {}

    /**
     * Abre una o varias materias de golpe.
     *
     * Se reciben en lote porque abrir un grupo es cargar el semestre completo:
     * hacerlo de una en una son diez viajes al servidor y diez recargas para
     * una sola decisión del usuario.
     */
    public function store(Request $request, Grupo $grupo): RedirectResponse
    {
        $datos = $request->validate([
            'plan_materia_ids' => ['required', 'array', 'min:1'],
            'plan_materia_ids.*' => ['integer', Rule::exists('plan_materias', 'id')->whereNull('deleted_at')],
        ], [
            'plan_materia_ids.required' => 'Elige al menos una materia.',
            'plan_materia_ids.min' => 'Elige al menos una materia.',
        ], ['plan_materia_ids' => 'materias']);

        $pedidas = array_values(array_unique(array_map('intval', $datos['plan_materia_ids'])));

        $yaAbiertas = AsignaturaGrupo::query()
            ->where('grupo_id', $grupo->id)
            ->whereIn('plan_materia_id', $pedidas)
            ->pluck('plan_materia_id')
            ->all();

        $nuevas = array_values(array_diff($pedidas, $yaAbiertas));

        if ($nuevas === []) {
            throw ValidationException::withMessages([
                'plan_materia_ids' => count($pedidas) === 1
                    ? 'Esa materia ya está abierta en este grupo.'
                    : 'Todas las materias elegidas ya están abiertas en este grupo.',
            ]);
        }

        $activa = SituacionAsignaturaGrupo::query()->where('clave', 'activa')->value('id');
        $conPlantilla = 0;

        DB::transaction(function () use ($nuevas, $grupo, $activa, &$conPlantilla): void {
            foreach ($nuevas as $planMateriaId) {
                $abierta = AsignaturaGrupo::create([
                    'grupo_id' => $grupo->id,
                    'plan_materia_id' => $planMateriaId,
                    'situacion_id' => $activa,
                ]);

                /*
                 * Si la escuela armó el curso en el plan, el grupo nace con él.
                 * Se copia AQUÍ y no la primera vez que alguien entra a la
                 * materia: el docente tiene que encontrarlo listo el día que le
                 * asignan el grupo, no descubrirlo apareciendo solo.
                 */
                if ($this->copiador->alAbrirMateria($abierta) !== null) {
                    $conPlantilla++;
                }
            }
        });

        $mensaje = count($nuevas) === 1
            ? 'Materia abierta en el grupo.'
            : count($nuevas).' materias abiertas en el grupo.';

        if ($conPlantilla > 0) {
            $mensaje .= $conPlantilla === 1
                ? ' Una traía curso en línea, ya cargado.'
                : " {$conPlantilla} traían curso en línea, ya cargado.";
        }

        // Si venían repetidas se dice, en vez de fingir que se abrieron todas.
        if ($yaAbiertas !== []) {
            return back()->with('advertencia', $mensaje.' '.count($yaAbiertas).' ya estaban abiertas y se omitieron.');
        }

        return back()->with('exito', $mensaje);
    }

    /**
     * Asigna un docente a la materia. La spec fija una regla que el esquema no
     * puede imponer —MySQL no admite índices únicos parciales—: a lo más UN
     * titular por materia, porque es quien firma el acta.
     */
    public function asignarDocente(Request $request, Grupo $grupo, AsignaturaGrupo $asignatura): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $datos = $request->validate([
            'persona_id' => ['required', 'integer', Rule::exists('docentes', 'persona_id')],
            'tipo' => ['required', Rule::in(['titular', 'adjunto'])],
        ], [], ['persona_id' => 'docente']);

        if ($datos['tipo'] === 'titular') {
            $otroTitular = $asignatura->docentes()
                ->wherePivot('tipo', 'titular')
                ->where('docentes.persona_id', '!=', $datos['persona_id'])
                ->exists();

            if ($otroTitular) {
                throw ValidationException::withMessages([
                    'persona_id' => 'La materia ya tiene un titular. Quítalo antes de asignar otro.',
                ]);
            }
        }

        $motivo = $this->motivoParaNoAsignar((int) $datos['persona_id'], (int) $grupo->ciclo_id);

        if ($motivo !== null) {
            throw ValidationException::withMessages(['persona_id' => $motivo]);
        }

        $asignatura->docentes()->syncWithoutDetaching([
            $datos['persona_id'] => ['tipo' => $datos['tipo']],
        ]);

        return back()->with('exito', 'Docente asignado.');
    }

    /**
     * Asigna el MISMO docente a varias materias del grupo de una vez.
     *
     * Abrir un grupo son diez o doce materias y el aviso «11 sin docente — nadie
     * podría firmar esas actas» se resolvía con once diálogos idénticos: elegir
     * al mismo profesor once veces. Al empezar un ciclo, eso se multiplica por
     * todos los grupos de la escuela.
     *
     * Las materias que YA tienen titular no se tocan ni hacen fallar la
     * operación: se informa cuántas se saltaron. Lo contrario obligaría a
     * deseleccionarlas a mano —volviendo al problema— o dejaría a medias una
     * asignación de doce por culpa de una.
     */
    public function asignarDocenteEnLote(Request $request, Grupo $grupo): RedirectResponse
    {
        $datos = $request->validate([
            'persona_id' => ['required', 'integer', Rule::exists('docentes', 'persona_id')],
            'tipo' => ['required', Rule::in(['titular', 'adjunto'])],
            'asignatura_ids' => ['required', 'array', 'min:1'],
            'asignatura_ids.*' => ['integer'],
        ], [], ['persona_id' => 'docente', 'asignatura_ids' => 'materias']);

        // Sólo materias de ESTE grupo: los ids llegan del cliente.
        $materias = AsignaturaGrupo::query()
            ->where('grupo_id', $grupo->id)
            ->whereIn('id', $datos['asignatura_ids'])
            ->with('docentes')
            ->get();

        $motivo = $this->motivoParaNoAsignar((int) $datos['persona_id'], (int) $grupo->ciclo_id);

        if ($motivo !== null) {
            throw ValidationException::withMessages(['persona_id' => $motivo]);
        }

        $asignadas = 0;
        $ocupadas = 0;
        $porLimite = 0;

        foreach ($materias as $materia) {
            // Un titular por materia: es quien firma el acta.
            $tieneOtroTitular = $datos['tipo'] === 'titular'
                && $materia->docentes->contains(
                    fn ($d) => $d->pivot->tipo === 'titular' && $d->persona_id !== (int) $datos['persona_id'],
                );

            if ($tieneOtroTitular) {
                $ocupadas++;

                continue;
            }

            /*
             * El límite se vuelve a mirar EN CADA VUELTA, contando lo que este
             * mismo lote lleva asignado. Comprobarlo sólo al principio dejaría
             * pasar las doce materias de un tirón a alguien con cupo para una:
             * el límite se rebasaría con la operación que venía a respetarlo.
             */
            if ($this->motivoParaNoAsignar((int) $datos['persona_id'], (int) $grupo->ciclo_id) !== null) {
                $porLimite++;

                continue;
            }

            $materia->docentes()->syncWithoutDetaching([
                $datos['persona_id'] => ['tipo' => $datos['tipo']],
            ]);
            $asignadas++;
        }

        $mensaje = $asignadas === 1
            ? 'Se asignó el docente a 1 materia.'
            : "Se asignó el docente a {$asignadas} materias.";

        if ($ocupadas > 0) {
            $mensaje .= $ocupadas === 1
                ? ' 1 se omitió porque ya tenía titular.'
                : " {$ocupadas} se omitieron porque ya tenían titular.";
        }

        if ($porLimite > 0) {
            $mensaje .= $porLimite === 1
                ? ' 1 se omitió porque alcanzó su carga máxima del ciclo.'
                : " {$porLimite} se omitieron porque alcanzó su carga máxima del ciclo.";
        }

        return back()->with($asignadas > 0 ? 'exito' : 'advertencia', $mensaje);
    }

    /**
     * Por qué NO se le puede dar esta materia, o null si sí.
     *
     * Aplica dos reglas que la escuela lleva pudiendo configurar desde siempre y
     * que NADIE leía: `docente.exige_cedula_para_asignar` y
     * `docente.max_materias_por_ciclo`. Una escuela podía encenderlas, creer que
     * había puesto un tope o un requisito, y seguir asignando igual. Un
     * interruptor que no hace lo que dice es peor que no tenerlo.
     *
     * ── El tope se cuenta por CICLO, no en total ───────────────────────────
     * «Cuántas materias puede impartir en el mismo ciclo» es lo que dice el
     * ajuste, y es lo que tiene sentido: la carga de un profesor es la de este
     * semestre, no la de su vida laboral.
     *
     * ── Y no desasigna a quien ya rebasa ───────────────────────────────────
     * Bajar el límite a la mitad a media escuela no puede dejar veinte materias
     * sin profesor de golpe. Lo dice la propia consecuencia declarada en el
     * catálogo, y aquí se cumple: esto sólo se consulta al ASIGNAR.
     */
    private function motivoParaNoAsignar(int $personaId, int $cicloId): ?string
    {
        $ajustes = app(Ajustes::class);

        if ($ajustes->bool(CatalogoAjustes::EXIGE_CEDULA)) {
            $cedula = Docente::query()->whereKey($personaId)->value('cedula_profesional');

            if (blank($cedula)) {
                return 'Ese docente no tiene cédula profesional capturada, y la escuela la exige para ponerlo al frente de un grupo.';
            }
        }

        $tope = $ajustes->entero(CatalogoAjustes::MAX_MATERIAS_DOCENTE);

        if ($tope <= 0) {
            return null;
        }

        $lleva = DB::table('docente_asignatura_grupo as dag')
            ->join('asignatura_grupo as ag', 'ag.id', '=', 'dag.asignatura_grupo_id')
            ->join('grupos as g', 'g.id', '=', 'ag.grupo_id')
            ->where('dag.persona_id', $personaId)
            ->where('g.ciclo_id', $cicloId)
            ->whereNull('dag.deleted_at')
            ->whereNull('ag.deleted_at')
            ->count();

        if ($lleva >= $tope) {
            return "Ese docente ya tiene {$lleva} materia(s) en este ciclo y el máximo de la escuela es {$tope}.";
        }

        return null;
    }

    public function quitarDocente(Grupo $grupo, AsignaturaGrupo $asignatura, int $personaId): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        /*
         * Se MARCA, no se borra.
         *
         * `detach()` tiraba la fila, así que de quien dio una materia durante
         * medio semestre y dejó de darla no quedaba ni rastro —mientras el acta
         * que firmó sigue nombrándolo—. La tabla siempre declaró
         * `$table->auditoria()`; lo que faltaba era poder usarlo: con la llave
         * compuesta vieja, la fila retirada seguía ocupando el par y volver a
         * asignarle esa materia reventaba con `Duplicate entry` PARA SIEMPRE.
         * La llave sustituta y el único con `deleted_at` lo destrabaron.
         */
        $asignatura->docentes()->updateExistingPivot($personaId, [
            'deleted_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        return back()->with('exito', 'Docente retirado.');
    }

    /**
     * Una materia con alumnos inscritos no se cierra borrándola: se les perdería
     * la inscripción y, si ya hay calificaciones, el acta.
     */
    public function destroy(Grupo $grupo, AsignaturaGrupo $asignatura): RedirectResponse
    {
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        if (Inscripcion::query()->where('asignatura_grupo_id', $asignatura->id)->exists()) {
            return back()->with('error', 'No se puede quitar: hay alumnos inscritos en esa materia.');
        }

        $asignatura->delete();

        return back()->with('exito', 'Materia retirada del grupo.');
    }

    /**
     * La lista de una materia: quién la cursa, quién la da y cómo van.
     *
     * ── Por qué hacía falta ────────────────────────────────────────────────
     * El grupo enseña cuántos inscritos tiene cada materia, y ahí se acababa:
     * para saber QUIÉNES eran había que ir al listado de alumnos y filtrar, y
     * para saber cómo iban, entrar a la captura del docente. Dos preguntas que
     * en control escolar se hacen juntas —«¿quién está en esta materia y cómo
     * va?»— y que no tenían una sola pantalla.
     *
     * ── El avance se calcula, no se lee ────────────────────────────────────
     * La calificación final sólo existe cuando se asienta el acta; hasta
     * entonces lo único que hay son componentes capturados. Se pondera aquí con
     * la MISMA calculadora que usa el cierre del acta, así que lo que se ve es
     * exactamente lo que saldría si se cerrara hoy —y no una segunda cuenta que
     * podría diverger—.
     */
    public function show(
        Grupo $grupo,
        AsignaturaGrupo $asignatura,
        CalculadoraCalificacion $calculadora,
        AsentadorActa $asentador,
    ): Response {
        // 404 y no 403: una materia de otro grupo no está en esta dirección.
        abort_unless($asignatura->grupo_id === $grupo->id, 404);

        $asignatura->load([
            'planMateria.asignatura',
            'planMateria.plan',
            'docentes.persona',
            'grupo.ciclo',
            'grupo.campus',
        ]);

        $esquema = $asentador->esquema($asignatura);
        $plan = $asignatura->planMateria?->plan;

        $inscripciones = Inscripcion::query()
            ->where('asignatura_grupo_id', $asignatura->id)
            ->with(['matriculaOferta.persona', 'situacion', 'calificaciones'])
            ->get()
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->values();

        $alumnos = $inscripciones->map(function (Inscripcion $inscripcion) use ($esquema, $plan, $calculadora) {
            $resultado = $calculadora->calcular($inscripcion, $esquema, $plan);
            $capturadas = $inscripcion->calificaciones->keyBy('esquema_evaluacion_id');

            return [
                'inscripcion_id' => $inscripcion->id,
                'matricula_id' => $inscripcion->matricula_oferta_id,
                'matricula' => $inscripcion->matriculaOferta?->matricula,
                'nombre' => $inscripcion->matriculaOferta?->persona?->nombreCompleto() ?? 'Sin nombre',
                'situacion' => $inscripcion->situacion?->nombre,
                'de_baja' => $inscripcion->situacion?->clave === 'baja',
                // Una celda por componente del esquema, en su mismo orden.
                'componentes' => $esquema
                    ->map(fn ($c) => $capturadas->get($c->id)?->calificacion)
                    ->values(),
                'final' => $resultado->final,
                'completa' => $resultado->completa,
                'aprobada' => $resultado->aprobada,
                'faltantes' => $resultado->faltantes,
                // Ya asentada: el número dejó de ser provisional.
                'asentada' => $inscripcion->calificacion_final !== null,
            ];
        })->all();

        return Inertia::render('ControlEscolar/Grupos/Materia', [
            'grupo' => [
                'id' => $grupo->id,
                'clave' => $grupo->clave,
                'ciclo' => $grupo->ciclo?->clave,
                'campus' => $grupo->campus?->nombre,
            ],
            'materia' => [
                'id' => $asignatura->id,
                'nombre' => $asignatura->planMateria?->asignatura?->nombre ?? 'Sin nombre',
                'clave' => $asignatura->planMateria?->clave_en_plan,
                'plan' => $plan?->nombre,
                'minima_aprobatoria' => $plan?->calificacion_minima_aprobatoria,
            ],
            'docentes' => $asignatura->docentes->map(fn ($d) => [
                'nombre' => $d->persona?->nombreCompleto() ?? 'Sin nombre',
                'tipo' => $d->pivot->tipo ?? 'titular',
            ])->values(),
            /*
             * Las columnas de la tabla salen del esquema del plan, no de un
             * listado fijo: cada materia puede evaluarse distinto y una tabla
             * con columnas inventadas mostraría celdas que no existen.
             */
            'esquema' => $esquema->map(fn ($c) => [
                'id' => $c->id,
                'componente' => $c->componente,
                'porcentaje' => (float) $c->porcentaje,
            ])->values(),
            'alumnos' => $alumnos,
        ]);
    }
}
