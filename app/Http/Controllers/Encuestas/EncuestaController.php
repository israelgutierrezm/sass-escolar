<?php

declare(strict_types=1);

namespace App\Http\Controllers\Encuestas;

use App\Enums\TipoPregunta;
use App\Http\Controllers\Controller;
use App\Models\Encuestas\Encuesta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los cuestionarios: las preguntas y nada más.
 *
 * Cuándo se aplican y a quién es de `AplicacionController`. Separarlo es lo que
 * permite tener una plantilla de evaluación docente y lanzarla cada semestre sin
 * volver a capturarla.
 */
class EncuestaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Encuestas/Cuestionarios', [
            'encuestas' => Encuesta::query()
                ->withCount(['preguntas', 'aplicaciones'])
                ->orderByDesc('es_plantilla')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Encuesta $e) => [
                    'id' => $e->id,
                    'titulo' => $e->titulo,
                    'descripcion' => $e->descripcion,
                    'es_plantilla' => $e->es_plantilla,
                    'activa' => $e->activa,
                    'preguntas' => $e->preguntas_count,
                    'aplicaciones' => $e->aplicaciones_count,
                ]),
            'tiposPregunta' => TipoPregunta::paraSelector(),
        ]);
    }

    public function ver(Encuesta $encuesta): Response
    {
        return Inertia::render('Encuestas/Cuestionario', [
            'encuesta' => [
                'id' => $encuesta->id,
                'titulo' => $encuesta->titulo,
                'descripcion' => $encuesta->descripcion,
                'es_plantilla' => $encuesta->es_plantilla,
                'activa' => $encuesta->activa,
                // Con aplicaciones detrás, editar las preguntas cambiaría el
                // significado de lo ya contestado: se avisa para que quien edita
                // sepa qué está tocando.
                'aplicada' => $encuesta->aplicaciones()->exists(),
            ],
            'preguntas' => $encuesta->preguntas()->with('opciones')->get()->map(fn ($p) => [
                'id' => $p->id,
                'texto' => $p->texto,
                'ayuda' => $p->ayuda,
                'tipo' => $p->tipo->value,
                'requerida' => $p->requerida,
                'config' => $p->config ?? [],
                'orden' => $p->orden,
                'opciones' => $p->opciones->map(fn ($o) => [
                    'id' => $o->id,
                    'texto' => $o->texto,
                    'valor' => $o->valor === null ? null : (float) $o->valor,
                ])->values(),
            ]),
            'tiposPregunta' => TipoPregunta::paraSelector(),
        ]);
    }

    public function guardar(Request $request, ?Encuesta $encuesta = null): RedirectResponse
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'es_plantilla' => ['boolean'],
            'activa' => ['boolean'],
        ], [], ['titulo' => 'el título']);

        $registro = $encuesta ?? new Encuesta;
        $registro->fill($datos)->save();

        return $encuesta === null
            ? to_route('tenant.encuestas.ver', $registro)->with('exito', 'Cuestionario creado. Agrégale preguntas.')
            : back(303)->with('exito', 'Cuestionario actualizado.');
    }

    /**
     * Guarda las preguntas completas del cuestionario.
     *
     * Se rehacen enteras, como los destinos de un aviso: son pocas, no hay nada
     * que conservar de una pregunta que se quitó, y calcular altas y bajas sería
     * más código para el mismo resultado.
     *
     * La excepción es cuando ya hay respuestas: ahí borrar una pregunta se
     * llevaría por delante lo contestado, así que se impide y se explica.
     */
    public function preguntas(Request $request, Encuesta $encuesta): RedirectResponse
    {
        $datos = $request->validate([
            'preguntas' => ['required', 'array', 'min:1'],
            'preguntas.*.texto' => ['required', 'string', 'max:500'],
            'preguntas.*.ayuda' => ['nullable', 'string', 'max:300'],
            'preguntas.*.tipo' => ['required', Rule::enum(TipoPregunta::class)],
            'preguntas.*.requerida' => ['boolean'],
            'preguntas.*.config' => ['nullable', 'array'],
            'preguntas.*.opciones' => ['array'],
            'preguntas.*.opciones.*.texto' => ['required', 'string', 'max:200'],
            'preguntas.*.opciones.*.valor' => ['nullable', 'numeric'],
        ], [
            'preguntas.required' => 'Un cuestionario sin preguntas no pregunta nada.',
        ]);

        if ($encuesta->aplicaciones()->whereHas('respuestas')->exists()) {
            return back(303)->with(
                'error',
                'Este cuestionario ya tiene respuestas. Cambiar sus preguntas dejaría los resultados atribuidos a preguntas que nadie vio: duplícalo y edita la copia.',
            );
        }

        DB::transaction(function () use ($encuesta, $datos) {
            $encuesta->preguntas()->delete();

            foreach ($datos['preguntas'] as $orden => $pregunta) {
                $tipo = TipoPregunta::from($pregunta['tipo']);

                $nueva = $encuesta->preguntas()->create([
                    'texto' => $pregunta['texto'],
                    'ayuda' => $pregunta['ayuda'] ?? null,
                    'tipo' => $tipo,
                    'requerida' => $pregunta['requerida'] ?? true,
                    'config' => $pregunta['config'] ?? null,
                    'orden' => $orden + 1,
                ]);

                if (! $tipo->requiereOpciones()) {
                    continue;
                }

                foreach ($pregunta['opciones'] ?? [] as $i => $opcion) {
                    $nueva->opciones()->create([
                        'texto' => $opcion['texto'],
                        'valor' => $opcion['valor'] ?? null,
                        'orden' => $i + 1,
                    ]);
                }
            }
        });

        return back(303)->with('exito', 'Preguntas guardadas.');
    }

    /** Duplicar es la vía para editar lo que ya se aplicó sin tocar lo contestado. */
    public function duplicar(Encuesta $encuesta): RedirectResponse
    {
        $copia = $encuesta->duplicar("{$encuesta->titulo} (copia)");

        return to_route('tenant.encuestas.ver', $copia)->with('exito', 'Copia creada.');
    }

    public function eliminar(Encuesta $encuesta): RedirectResponse
    {
        if ($encuesta->aplicaciones()->exists()) {
            return back(303)->with(
                'error',
                'Este cuestionario ya se aplicó. Desactívalo en vez de borrarlo: sus resultados dependen de él.',
            );
        }

        $encuesta->delete();

        return back(303)->with('exito', 'Cuestionario eliminado.');
    }
}
