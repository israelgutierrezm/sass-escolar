<?php

declare(strict_types=1);

namespace App\Http\Controllers\Encuestas;

use App\Http\Controllers\Controller;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Pregunta;
use App\Models\Encuestas\Sujeto;
use App\Models\Identidad\Usuario;
use App\Services\Encuestas\EncuestasDeUsuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las encuestas desde el lado de quien las contesta.
 *
 * Sin permiso que valga, como los avisos: que te pregunten no es una facultad
 * que se otorgue. Lo único que decide qué ve cada quien son los destinatarios
 * de la aplicación, resueltos contra su persona.
 */
class MisEncuestasController extends Controller
{
    public function __construct(private readonly EncuestasDeUsuario $encuestas) {}

    public function index(Request $request): Response
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        return Inertia::render('Encuestas/Mias', [
            'pendientes' => $this->encuestas->pendientes($usuario),
        ]);
    }

    /** El cuestionario, listo para contestarse. */
    public function contestar(Request $request, AplicacionEncuesta $aplicacion, ?Sujeto $sujeto = null): Response
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        // 404 y no 403: para quien no es destinatario, esa encuesta no está ahí.
        abort_unless($this->leToca($usuario, $aplicacion, $sujeto), 404);

        $aplicacion->load('encuesta.preguntas.opciones');
        $sujeto?->load(['persona', 'materia.planMateria.asignatura', 'materia.grupo']);

        return Inertia::render('Encuestas/Contestar', [
            'aplicacion' => [
                'id' => $aplicacion->id,
                'titulo' => $aplicacion->titulo,
                'instrucciones' => $aplicacion->instrucciones,
                'anonima' => $aplicacion->anonima,
                'obligatoria' => $aplicacion->obligatoria,
                'cierra_en' => $aplicacion->cierra_en?->toDateTimeString(),
            ],
            'sujeto' => $sujeto === null ? null : [
                'id' => $sujeto->id,
                'docente' => $sujeto->persona?->nombreCompleto(),
                'materia' => $sujeto->materia?->planMateria?->asignatura?->nombre,
                'grupo' => $sujeto->materia?->grupo?->clave,
            ],
            'preguntas' => $aplicacion->encuesta->preguntas->map(fn (Pregunta $p) => [
                'id' => $p->id,
                'texto' => $p->texto,
                'ayuda' => $p->ayuda,
                'tipo' => $p->tipo->value,
                'requerida' => $p->requerida,
                'config' => $p->config ?? [],
                'opciones' => $p->opciones->map(fn ($o) => ['id' => $o->id, 'texto' => $o->texto])->values(),
            ])->values(),
        ]);
    }

    public function guardar(Request $request, AplicacionEncuesta $aplicacion, ?Sujeto $sujeto = null): RedirectResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $datos = $request->validate([
            'respuestas' => ['array'],
        ]);

        $this->exigirRequeridas($aplicacion, $datos['respuestas'] ?? []);

        $guardado = $this->encuestas->guardar($usuario, $aplicacion, $sujeto, $datos['respuestas'] ?? []);

        abort_unless($guardado, 404);

        return to_route('tenant.misencuestas.index')->with('exito', '¡Listo! Gracias por contestar.');
    }

    /**
     * Las requeridas se comprueban en el SERVIDOR además de en la pantalla.
     *
     * Una encuesta a medias no se puede completar después —se guarda de una
     * vez y ya no se puede volver a entrar—, así que dejar pasar un salto
     * significa perder ese dato para siempre.
     *
     * @param  array<int, mixed>  $respuestas
     */
    private function exigirRequeridas(AplicacionEncuesta $aplicacion, array $respuestas): void
    {
        $faltan = $aplicacion->encuesta->preguntas
            ->filter(fn (Pregunta $p) => $p->requerida)
            ->filter(fn (Pregunta $p) => ! $this->contestada($respuestas[$p->id] ?? null))
            ->map(fn (Pregunta $p) => $p->texto)
            ->values();

        if ($faltan->isNotEmpty()) {
            abort(422, 'Faltan preguntas por contestar: '.$faltan->implode('; '));
        }
    }

    private function contestada(mixed $valor): bool
    {
        return $valor !== null && $valor !== '' && $valor !== [];
    }

    private function leToca(Usuario $usuario, AplicacionEncuesta $aplicacion, ?Sujeto $sujeto): bool
    {
        return collect($this->encuestas->pendientes($usuario))->contains(
            fn (array $p) => $p['aplicacion_id'] === $aplicacion->id && $p['sujeto_id'] === $sujeto?->id,
        );
    }
}
