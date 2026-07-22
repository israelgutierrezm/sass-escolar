<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Formularios\CampoFormulario;
use App\Models\Formularios\Formulario;
use App\Models\Formularios\FormularioAsignacion;
use App\Models\Formularios\OpcionCampo;
use App\Models\Formularios\TipoCampo;
use App\Models\Identidad\Rol;
use App\Models\Landlord\NivelEstudio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Constructor de formularios dinámicos.
 *
 * El motor completo —formularios versionados, once tipos de campo, opciones,
 * campos condicionales y asignación por nivel, carrera, oferta o rol— vive en
 * la base desde la Fase 1 y nunca tuvo interfaz: para pedir un dato nuevo había
 * que insertar filas a mano. Esto es esa interfaz.
 *
 * **Versionar en vez de mutar** es la regla que gobierna todo lo demás: las
 * respuestas ya capturadas apuntan a un campo concreto, así que cambiar un
 * formulario que ya se respondió reescribiría la pregunta sin tocar la
 * respuesta, y el expediente diría algo que nadie contestó. Un formulario en
 * uso se congela; para cambiarlo se publica una versión nueva.
 */
class FormularioController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Formularios/Index', [
            'formularios' => Formulario::query()
                ->withCount(['campos', 'asignaciones'])
                ->orderBy('clave')
                ->orderByDesc('version')
                ->get()
                ->map(fn (Formulario $f) => [
                    'id' => $f->id,
                    'clave' => $f->clave,
                    'titulo' => $f->titulo,
                    'version' => $f->version,
                    'obligatorio' => $f->obligatorio,
                    'orden' => $f->orden,
                    'campos' => $f->campos_count,
                    'asignaciones' => $f->asignaciones_count,
                    'respuestas' => $this->respuestasDe($f),
                    'es_ultima' => $this->esUltimaVersion($f),
                ]),
            'puedeEditar' => $request->user()->can('gestionar-formularios'),
        ]);
    }

    /** El constructor: campos, opciones, condicionales y asignaciones. */
    public function show(Request $request, Formulario $formulario): Response
    {
        $formulario->load([
            'campos.tipoCampo:id,clave,nombre',
            'campos.opciones',
            'asignaciones',
        ]);

        $respuestas = $this->respuestasDe($formulario);

        return Inertia::render('Formularios/Constructor', [
            'formulario' => [
                'id' => $formulario->id,
                'clave' => $formulario->clave,
                'titulo' => $formulario->titulo,
                'instruccion' => $formulario->instruccion,
                'icono' => $formulario->icono,
                'orden' => $formulario->orden,
                'porcentaje' => $formulario->porcentaje,
                'obligatorio' => $formulario->obligatorio,
                'version' => $formulario->version,
            ],
            'campos' => $formulario->campos->sortBy('orden')->values()->map(fn (CampoFormulario $c) => [
                'id' => $c->id,
                'pregunta' => $c->pregunta,
                'descripcion' => $c->descripcion,
                'tipo_campo_id' => $c->tipo_campo_id,
                'tipo' => $c->tipoCampo?->nombre,
                'tipo_clave' => $c->tipoCampo?->clave,
                'obligatorio' => $c->obligatorio,
                'orden' => $c->orden,
                'regex' => $c->regex,
                'mensaje_error' => $c->mensaje_error,
                'min' => $c->min,
                'max' => $c->max,
                'campo_padre_id' => $c->campo_padre_id,
                'condicional' => $c->condicional,
                'opciones' => $c->opciones->sortBy('orden')->values()->map(fn (OpcionCampo $o) => [
                    'id' => $o->id,
                    'valor' => $o->valor,
                    'etiqueta' => $o->etiqueta,
                    'orden' => $o->orden,
                ]),
            ]),
            'tiposCampo' => TipoCampo::query()->orderBy('id')->get(['id', 'clave', 'nombre']),
            'asignaciones' => $formulario->asignaciones->map(fn (FormularioAsignacion $a) => [
                'id' => $a->id,
                'tipo' => $a->aplica_a_tipo,
                'destino_id' => $a->aplica_a_id,
                'destino' => $this->nombreDestino($a),
                'obligatorio' => $a->obligatorio,
            ]),
            'destinos' => $this->destinos(),
            // Con respuestas capturadas el formulario se congela: editarlo
            // reescribiría preguntas que alguien ya contestó.
            'respuestas' => $respuestas,
            'congelado' => $respuestas > 0,
            'puedeEditar' => $request->user()->can('gestionar-formularios'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'clave' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'titulo' => ['required', 'string', 'max:150'],
            'instruccion' => ['nullable', 'string', 'max:255'],
            'obligatorio' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ], [
            'clave.regex' => 'La clave usa minúsculas, números y guion bajo (ej. datos_generales).',
        ]);

        $yaExiste = Formulario::withTrashed()->where('clave', $datos['clave'])->exists();

        if ($yaExiste) {
            throw ValidationException::withMessages([
                'clave' => 'Ya hay un formulario con esa clave. Para cambiarlo, publica una versión nueva desde su constructor.',
            ]);
        }

        $formulario = Formulario::create([
            ...$datos,
            'version' => 1,
            'orden' => $datos['orden'] ?? 0,
        ]);

        return redirect()
            ->route('tenant.formularios.show', $formulario)
            ->with('exito', 'Formulario creado. Agrégale sus campos.');
    }

    public function update(Request $request, Formulario $formulario): RedirectResponse
    {
        $this->exigirEditable($formulario);

        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'instruccion' => ['nullable', 'string', 'max:255'],
            'obligatorio' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'porcentaje' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        // La clave NO se edita: identifica al formulario a través de sus
        // versiones y es la que usan las respuestas para saber qué se contestó.
        $formulario->update($datos);

        return back()->with('exito', 'Formulario actualizado.');
    }

    /**
     * Publica una versión nueva copiando campos, opciones y asignaciones.
     *
     * Es la única forma de cambiar un formulario que ya se respondió: la
     * versión vieja queda intacta con sus respuestas, y la nueva se usa de aquí
     * en adelante.
     */
    public function versionar(Formulario $formulario): RedirectResponse
    {
        $nueva = DB::transaction(function () use ($formulario) {
            $formulario->load(['campos.opciones', 'asignaciones']);

            $copia = Formulario::create([
                'clave' => $formulario->clave,
                'titulo' => $formulario->titulo,
                'instruccion' => $formulario->instruccion,
                'icono' => $formulario->icono,
                'orden' => $formulario->orden,
                'porcentaje' => $formulario->porcentaje,
                'obligatorio' => $formulario->obligatorio,
                // `withTrashed`: una version borrada sigue ocupando el unique
                // (clave, version), y sin contarla el reintento choca con ella.
                'version' => $this->siguienteVersion($formulario->clave),
            ]);

            // Los campos se copian en dos pasadas: primero todos, y luego se
            // re-atan los condicionales. Un campo puede depender de otro que
            // todavía no existía cuando se copió.
            $equivalencias = [];

            foreach ($formulario->campos as $campo) {
                $nuevo = CampoFormulario::create([
                    'formulario_id' => $copia->id,
                    'tipo_campo_id' => $campo->tipo_campo_id,
                    'pregunta' => $campo->pregunta,
                    'descripcion' => $campo->descripcion,
                    'obligatorio' => $campo->obligatorio,
                    'regex' => $campo->regex,
                    'mensaje_error' => $campo->mensaje_error,
                    'orden' => $campo->orden,
                    'condicional' => $campo->condicional,
                    'min' => $campo->min,
                    'max' => $campo->max,
                    'promueve_a' => $campo->promueve_a,
                ]);

                $equivalencias[$campo->id] = $nuevo->id;

                foreach ($campo->opciones as $opcion) {
                    OpcionCampo::create([
                        'campo_formulario_id' => $nuevo->id,
                        'valor' => $opcion->valor,
                        'etiqueta' => $opcion->etiqueta,
                        'orden' => $opcion->orden,
                    ]);
                }
            }

            foreach ($formulario->campos as $campo) {
                if ($campo->campo_padre_id !== null && isset($equivalencias[$campo->campo_padre_id])) {
                    CampoFormulario::whereKey($equivalencias[$campo->id])
                        ->update(['campo_padre_id' => $equivalencias[$campo->campo_padre_id]]);
                }
            }

            foreach ($formulario->asignaciones as $asignacion) {
                FormularioAsignacion::create([
                    'formulario_id' => $copia->id,
                    'aplica_a_tipo' => $asignacion->aplica_a_tipo,
                    'aplica_a_id' => $asignacion->aplica_a_id,
                    'obligatorio' => $asignacion->obligatorio,
                ]);
            }

            return $copia;
        });

        return redirect()
            ->route('tenant.formularios.show', $nueva)
            ->with('exito', "Versión {$nueva->version} publicada. La anterior conserva sus respuestas.");
    }

    public function destroy(Formulario $formulario): RedirectResponse
    {
        if ($this->respuestasDe($formulario) > 0) {
            return back()->with('error', 'No se puede eliminar: ya tiene respuestas capturadas.');
        }

        $formulario->delete();

        return redirect()->route('tenant.formularios.index')->with('exito', 'Formulario eliminado.');
    }

    /*
    |--------------------------------------------------------------------------
    | Asignaciones: a quién se le muestra
    |--------------------------------------------------------------------------
    */

    public function asignar(Request $request, Formulario $formulario): RedirectResponse
    {
        $datos = $request->validate([
            'aplica_a_tipo' => ['required', Rule::in(['nivel', 'carrera', 'oferta', 'rol'])],
            'aplica_a_id' => ['required', 'integer'],
            'obligatorio' => ['boolean'],
        ], [], ['aplica_a_tipo' => 'tipo de destino', 'aplica_a_id' => 'destino']);

        $duplicada = FormularioAsignacion::query()
            ->where('formulario_id', $formulario->id)
            ->where('aplica_a_tipo', $datos['aplica_a_tipo'])
            ->where('aplica_a_id', $datos['aplica_a_id'])
            ->exists();

        if ($duplicada) {
            throw ValidationException::withMessages([
                'aplica_a_id' => 'Este formulario ya está asignado a ese destino.',
            ]);
        }

        FormularioAsignacion::create([...$datos, 'formulario_id' => $formulario->id]);

        return back()->with('exito', 'Asignación agregada.');
    }

    public function desasignar(Formulario $formulario, FormularioAsignacion $asignacion): RedirectResponse
    {
        abort_unless($asignacion->formulario_id === $formulario->id, 404);

        $asignacion->delete();

        return back()->with('exito', 'Asignación retirada.');
    }

    /*
    |--------------------------------------------------------------------------
    | Apoyo
    |--------------------------------------------------------------------------
    */

    /**
     * Siguiente número de versión libre para una clave.
     *
     * Cuenta también las versiones BORRADAS: el soft delete no libera el índice
     * único (clave, version), así que ignorarlas hace que el segundo intento de
     * versionar choque contra una fila que ya nadie ve.
     */
    private function siguienteVersion(string $clave): int
    {
        return (int) Formulario::withTrashed()->where('clave', $clave)->max('version') + 1;
    }

    /** Respuestas capturadas contra los campos de este formulario. */
    private function respuestasDe(Formulario $formulario): int
    {
        return DB::table('respuestas_campo')
            ->whereIn(
                'campo_formulario_id',
                DB::table('campos_formulario')->where('formulario_id', $formulario->id)->select('id')
            )
            ->count();
    }

    private function esUltimaVersion(Formulario $formulario): bool
    {
        return $formulario->version === Formulario::query()
            ->where('clave', $formulario->clave)
            ->max('version');
    }

    /**
     * Un formulario con respuestas se congela. Se lanza aquí y no en la
     * interfaz porque la interfaz puede estar desactualizada.
     */
    private function exigirEditable(Formulario $formulario): void
    {
        if ($this->respuestasDe($formulario) > 0) {
            throw ValidationException::withMessages([
                'formulario' => 'Ya tiene respuestas capturadas: publica una versión nueva para cambiarlo.',
            ]);
        }
    }

    /** Nombre legible del destino de una asignación (es polimórfico, sin FK). */
    private function nombreDestino(FormularioAsignacion $asignacion): string
    {
        return match ($asignacion->aplica_a_tipo) {
            'nivel' => NivelEstudio::find($asignacion->aplica_a_id)?->nombre ?? 'nivel desconocido',
            'carrera' => Carrera::find($asignacion->aplica_a_id)?->nombre ?? 'carrera desconocida',
            'oferta' => $this->nombreOferta($asignacion->aplica_a_id),
            'rol' => Rol::find($asignacion->aplica_a_id)?->nombre ?? 'rol desconocido',
            default => 'destino desconocido',
        };
    }

    private function nombreOferta(int $id): string
    {
        $oferta = Oferta::with(['carrera:id,nombre', 'plan:id,nombre'])->find($id);

        return $oferta === null
            ? 'oferta desconocida'
            : trim(($oferta->carrera?->nombre ?? '').' · '.($oferta->plan?->nombre ?? ''));
    }

    /**
     * Destinos posibles de una asignación, por tipo.
     *
     * @return array<string, mixed>
     */
    private function destinos(): array
    {
        return [
            'nivel' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre'])
                ->map(fn ($n) => ['id' => $n->id, 'nombre' => $n->nombre]),
            'carrera' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'oferta' => Oferta::query()->with(['carrera:id,nombre', 'plan:id,nombre'])->get()
                ->map(fn (Oferta $o) => [
                    'id' => $o->id,
                    'nombre' => trim(($o->carrera?->nombre ?? '').' · '.($o->plan?->nombre ?? '')),
                ]),
            'rol' => Rol::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
