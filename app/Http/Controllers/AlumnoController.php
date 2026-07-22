<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Genero;
use App\Models\Landlord\Sexo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alumnos: buscar, consultar el expediente completo y corregir sus datos.
 *
 * El "alumno" es `matricula_oferta`, no la persona: la misma persona puede
 * cursar una licenciatura y una maestría, y cada una tiene su matrícula, su
 * kárdex y su situación. Por eso el listado es de matrículas y no de personas
 * —quien busca a alguien en control escolar busca una matrícula concreta—.
 *
 * Lo que se edita aquí son los datos de IDENTIDAD (que viven en `personas` y
 * alcanzan a todas sus matrículas) y la SITUACIÓN escolar de esta matrícula.
 * La carga de materias se maneja en Inscripciones, que es donde vive esa
 * validación; duplicarla aquí daría dos verdades sobre lo mismo.
 */
class AlumnoController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'carrera_id' => $request->query('carrera_id'),
            'campus_id' => $request->query('campus_id'),
            'situacion_id' => $request->query('situacion_id'),
            'estatus' => $request->query('estatus'),
        ];

        $alumnos = MatriculaOferta::query()
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp,email,celular,foto_url',
                'oferta.carrera:id,nombre',
                'oferta.plan:id,nombre',
                'oferta.campus:id,nombre',
                'situacion:id,nombre',
            ])
            ->when($filtros['busqueda'] !== '', fn ($q) => $this->buscar($q, $filtros['busqueda']))
            ->when($filtros['carrera_id'], fn ($q, $id) => $q->whereHas('oferta', fn ($o) => $o->where('carrera_id', $id)))
            ->when($filtros['campus_id'], fn ($q, $id) => $q->whereHas('oferta', fn ($o) => $o->where('campus_id', $id)))
            ->when($filtros['situacion_id'], fn ($q, $id) => $q->where('situacion_id', $id))
            ->when($filtros['estatus'], fn ($q, $e) => $q->where('estatus', $e))
            ->orderBy('matricula')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre_completo' => $m->persona?->nombreCompleto(),
                'curp' => $m->persona?->curp,
                'email' => $m->persona?->email,
                'foto' => $m->persona?->urlFoto(),
                'carrera' => $m->oferta?->carrera?->nombre,
                'plan' => $m->oferta?->plan?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
                'situacion' => $m->situacion?->nombre,
                'estatus' => $m->estatus,
                'generacion' => $m->generacion,
            ]);

        return Inertia::render('Alumnos/Index', [
            'alumnos' => $alumnos,
            'filtros' => $filtros,
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'situaciones' => SituacionAlumno::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('editar-alumnos'),
        ]);
    }

    /** Expediente del alumno: identidad, kárdex y su carga por ciclo. */
    public function show(Request $request, MatriculaOferta $alumno): Response
    {
        $alumno->load([
            'persona.sexo',
            'persona.genero',
            'persona.entidadNacimiento',
            'oferta.carrera',
            'oferta.plan',
            'oferta.campus',
            'oferta.turno',
            'situacion',
        ]);

        $historial = Historial::query()
            ->with([
                'planMateria.asignatura:id,nombre,creditos',
                'ciclo:id,clave',
                'estatus:id,clave,nombre',
                'tipoEvaluacion:id,nombre',
                'observacion:id,nombre',
            ])
            ->where('matricula_oferta_id', $alumno->id)
            ->get()
            ->sortBy([['ciclo.clave', 'asc'], ['planMateria.clave_en_plan', 'asc']])
            ->values();

        $aprobadas = $historial->filter(fn (Historial $h) => $h->estatus?->clave === 'aprobada');

        return Inertia::render('Alumnos/Detalle', [
            'alumno' => [
                'id' => $alumno->id,
                'matricula' => $alumno->matricula,
                'generacion' => $alumno->generacion,
                'fecha_ingreso' => $alumno->fecha_ingreso?->toDateString(),
                'estatus' => $alumno->estatus,
                'situacion_id' => $alumno->situacion_id,
                'situacion' => $alumno->situacion?->nombre,
                'carrera' => $alumno->oferta?->carrera?->nombre,
                'plan' => $alumno->oferta?->plan?->nombre,
                'campus' => $alumno->oferta?->campus?->nombre,
                'turno' => $alumno->oferta?->turno?->nombre,
            ],
            'persona' => [
                'id' => $alumno->persona?->id,
                'nombre' => $alumno->persona?->nombre,
                'primer_apellido' => $alumno->persona?->primer_apellido,
                'segundo_apellido' => $alumno->persona?->segundo_apellido,
                'curp' => $alumno->persona?->curp,
                'rfc' => $alumno->persona?->rfc,
                'fecha_nacimiento' => $alumno->persona?->fecha_nacimiento?->toDateString(),
                'sexo_id' => $alumno->persona?->sexo_id,
                'genero_id' => $alumno->persona?->genero_id,
                'email' => $alumno->persona?->email,
                'correo_institucional' => $alumno->persona?->correo_institucional,
                'celular' => $alumno->persona?->celular,
                'foto' => $alumno->persona?->urlFoto(),
                'entidad_nacimiento' => $alumno->persona?->entidadNacimiento?->nombre,
            ],
            // Otras matrículas de la MISMA persona: es el caso que justifica que
            // el alumno sea la matrícula y no la persona.
            'otrasMatriculas' => MatriculaOferta::query()
                ->with('oferta.carrera:id,nombre')
                ->where('persona_id', $alumno->persona_id)
                ->whereKeyNot($alumno->id)
                ->get()
                ->map(fn (MatriculaOferta $m) => [
                    'id' => $m->id,
                    'matricula' => $m->matricula,
                    'carrera' => $m->oferta?->carrera?->nombre,
                    'estatus' => $m->estatus,
                ]),
            'historial' => $historial->map(fn (Historial $h) => [
                'id' => $h->id,
                'clave_en_plan' => $h->planMateria?->clave_en_plan,
                'materia' => $h->planMateria?->asignatura?->nombre,
                'creditos' => $h->planMateria?->creditos_en_plan ?? $h->planMateria?->asignatura?->creditos,
                'ciclo' => $h->ciclo?->clave,
                'calificacion' => $h->calificacion,
                'estatus' => $h->estatus?->nombre,
                'estatus_clave' => $h->estatus?->clave,
                'tipo_evaluacion' => $h->tipoEvaluacion?->nombre,
                'acta_folio' => $h->acta_folio,
                'observacion' => $h->observacion?->nombre,
            ]),
            'resumen' => [
                'materias_cursadas' => $historial->count(),
                'aprobadas' => $aprobadas->count(),
                'reprobadas' => $historial->filter(fn (Historial $h) => $h->estatus?->clave === 'reprobada')->count(),
                'creditos' => round($aprobadas->sum(
                    fn (Historial $h) => (float) ($h->planMateria?->creditos_en_plan ?? $h->planMateria?->asignatura?->creditos ?? 0)
                ), 2),
                'promedio' => $this->promedio($historial),
                'creditos_del_plan' => $alumno->oferta?->plan?->total_creditos,
            ],
            'carga' => $this->cargaPorCiclo($alumno),
            'situaciones' => SituacionAlumno::query()->orderBy('id')->get(['id', 'nombre']),
            'sexos' => Sexo::query()->orderBy('id')->get(['id', 'nombre']),
            'generos' => Genero::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('editar-alumnos'),
        ]);
    }

    /**
     * Corrige la identidad de la persona y la situación de esta matrícula.
     *
     * Van juntos en una pantalla pero se guardan en dos tablas distintas, y eso
     * importa: el nombre corregido alcanza a TODAS las matrículas de la persona
     * —es la misma—, mientras que la situación es de esta inscripción a oferta.
     */
    public function update(Request $request, MatriculaOferta $alumno): RedirectResponse
    {
        $persona = $alumno->persona;

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'size:18', Rule::unique('personas', 'curp')->ignore($persona?->id)->whereNull('deleted_at')],
            'rfc' => ['nullable', 'string', 'max:13'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'sexo_id' => ['required', 'integer'],
            'genero_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:150'],
            'correo_institucional' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],

            'situacion_id' => ['required', 'integer', Rule::exists('situaciones_alumno', 'id')->whereNull('deleted_at')],
            'estatus' => ['required', Rule::in(['activo', 'egresado', 'baja'])],
            'generacion' => ['nullable', 'string', 'max:100'],
        ], [
            'curp.size' => 'La CURP tiene 18 caracteres.',
            'curp.unique' => 'Esa CURP ya está registrada en otra persona.',
        ], [
            'sexo_id' => 'sexo',
            'genero_id' => 'género',
            'situacion_id' => 'situación',
        ]);

        DB::transaction(function () use ($alumno, $persona, $datos): void {
            $persona?->update([
                'nombre' => $datos['nombre'],
                'primer_apellido' => $datos['primer_apellido'],
                'segundo_apellido' => $datos['segundo_apellido'] ?? null,
                'curp' => $datos['curp'] ?? null,
                'rfc' => $datos['rfc'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'sexo_id' => $datos['sexo_id'],
                'genero_id' => $datos['genero_id'] ?? null,
                'email' => $datos['email'] ?? null,
                'correo_institucional' => $datos['correo_institucional'] ?? null,
                'celular' => $datos['celular'] ?? null,
            ]);

            $alumno->update([
                'situacion_id' => $datos['situacion_id'],
                'estatus' => $datos['estatus'],
                'generacion' => $datos['generacion'] ?? null,
            ]);
        });

        return back()->with('exito', 'Datos del alumno actualizados.');
    }

    /**
     * Búsqueda por matrícula, nombre o CURP.
     *
     * La matrícula se busca aparte porque vive en `matricula_oferta` y no en
     * `personas`, y es lo primero que teclea control escolar. Sobre la persona
     * se usa LIKE y no el índice FULLTEXT: con FULLTEXT, escribir "Her" no
     * encuentra "Hernández" —indexa palabras completas— y la búsqueda de una
     * pantalla se teclea de a poco. Si el volumen lo pide, aquí es donde habría
     * que cambiar a FULLTEXT en modo booleano con comodín.
     *
     * @param  Builder<MatriculaOferta>  $query
     * @return Builder<MatriculaOferta>
     */
    private function buscar(Builder $query, string $termino): Builder
    {
        $like = '%'.str_replace(' ', '%', $termino).'%';

        return $query->where(fn ($q) => $q
            ->where('matricula', 'like', "%{$termino}%")
            ->orWhereHas('persona', fn ($p) => $p
                ->where('curp', 'like', "%{$termino}%")
                ->orWhereRaw("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ?", [$like])));
    }

    /**
     * Materias que lleva por ciclo, de la más reciente hacia atrás.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cargaPorCiclo(MatriculaOferta $alumno): array
    {
        return Inscripcion::query()
            ->with([
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
                'asignaturaGrupo.grupo:id,clave',
                'ciclo:id,clave,nombre,fecha_inicio',
                'situacion:id,clave,nombre',
            ])
            ->where('matricula_oferta_id', $alumno->id)
            ->get()
            ->groupBy(fn (Inscripcion $i) => $i->ciclo?->clave ?? 'sin ciclo')
            ->map(fn ($inscripciones, $clave) => [
                'ciclo' => $clave,
                'materias' => $inscripciones->map(fn (Inscripcion $i) => [
                    'id' => $i->id,
                    'clave_en_plan' => $i->asignaturaGrupo?->planMateria?->clave_en_plan,
                    'materia' => $i->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                    'grupo' => $i->asignaturaGrupo?->grupo?->clave,
                    'tipo' => $i->tipo,
                    'situacion' => $i->situacion?->nombre,
                    'de_baja' => $i->situacion?->clave === 'baja',
                    'calificacion_final' => $i->calificacion_final,
                ])->values(),
            ])
            ->sortByDesc('ciclo')
            ->values()
            ->all();
    }

    /**
     * Promedio de lo calificado. Solo cuenta lo que tiene número: una materia
     * en curso no promedia como cero.
     */
    private function promedio($historial): ?float
    {
        $conCalificacion = $historial->filter(fn (Historial $h) => $h->calificacion !== null);

        if ($conCalificacion->isEmpty()) {
            return null;
        }

        return round((float) $conCalificacion->avg(fn (Historial $h) => (float) $h->calificacion), 2);
    }
}
