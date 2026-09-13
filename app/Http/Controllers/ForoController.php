<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\ForoTema;
use App\Services\Lms\ForoDeActividad;
use App\Services\Lms\SalaDeMateria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El foro de una actividad de tipo foro.
 *
 * Igual que el chat: un solo controlador para las dos partes. Lo que cambia
 * según quién eres es poder moderar —fijar, cerrar, borrar lo ajeno—, no el
 * resto. La regla (leer, participar, moderar, y que PARTICIPAR CUENTA COMO
 * ENTREGAR) vive en `ForoDeActividad`, que comparte con la app; aquí sólo se
 * autoriza y se traduce a Inertia.
 */
class ForoController extends Controller
{
    public function __construct(
        private readonly SalaDeMateria $sala,
        private readonly ForoDeActividad $foro,
    ) {}

    public function show(Request $request, AsignaturaGrupo $materia, Actividad $actividad): Response
    {
        $yo = $this->autorizar($request, $materia, $actividad);
        $moderador = $this->sala->esDocente($materia, $yo);

        $temas = $this->foro->temas($actividad);
        $abierto = $this->temaPedido($request, $temas);

        return Inertia::render('Lms/Foro', [
            'materia' => [
                'id' => $materia->id,
                'nombre' => $materia->planMateria?->asignatura?->nombre ?? 'Materia',
            ],
            'actividad' => [
                'id' => $actividad->id,
                'titulo' => $actividad->titulo,
                'instrucciones' => $actividad->instrucciones,
                // Sin SEGUNDOS: es un plazo dentro de una frase —«Participa hasta
                // el …»—, y «21:17:34» no le dice nada a nadie.
                'cierra_en' => $actividad->cierra_en?->format('d/m/Y H:i'),
                'abierta' => $actividad->abierta(),
                'pondera' => $actividad->pondera(),
                'puntos' => (float) $actividad->puntos,
            ],
            'volver' => $moderador
                ? ['href' => "/docencia/materias/{$materia->id}", 'texto' => 'La materia']
                : ['href' => "/mis-cursos/{$materia->id}", 'texto' => 'La materia'],
            'yo' => $yo,
            'moderador' => $moderador,
            'temas' => $this->foro->listaTemas($temas),
            'abierto' => $this->foro->detalle($abierto),
        ]);
    }

    /** Abre un tema nuevo. */
    public function crearTema(Request $request, AsignaturaGrupo $materia, Actividad $actividad): RedirectResponse
    {
        $yo = $this->autorizar($request, $materia, $actividad);

        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:200'],
            'cuerpo' => ['required', 'string', 'max:20000'],
        ], [], ['cuerpo' => 'contenido']);

        $r = $this->foro->crearTema($actividad, $yo, $datos['titulo'], $datos['cuerpo']);

        if ($r['error'] !== null) {
            return back()->with('error', $r['error']);
        }

        return redirect("/materias/{$materia->id}/foros/{$actividad->id}?tema={$r['tema']->id}");
    }

    /** Responde a un tema, o a otra respuesta. */
    public function responder(Request $request, AsignaturaGrupo $materia, Actividad $actividad, ForoTema $tema): RedirectResponse
    {
        $yo = $this->autorizar($request, $materia, $actividad);
        abort_unless((int) $tema->actividad_id === $actividad->id, 404);

        $datos = $request->validate([
            'cuerpo' => ['required', 'string', 'max:20000'],
            'responde_a_id' => ['nullable', 'integer'],
        ], [], ['cuerpo' => 'respuesta']);

        $r = $this->foro->responder($actividad, $tema, $yo, $datos['cuerpo'], $datos['responde_a_id']);

        if ($r['error'] !== null) {
            return back()->with('error', $r['error']);
        }

        return back();
    }

    /** Fijar o cerrar un tema: solo el docente. */
    public function moderar(Request $request, AsignaturaGrupo $materia, Actividad $actividad, ForoTema $tema): RedirectResponse
    {
        $yo = $this->autorizar($request, $materia, $actividad);
        abort_unless((int) $tema->actividad_id === $actividad->id, 404);

        if (! $this->sala->esDocente($materia, $yo)) {
            throw new AccessDeniedHttpException('Solo el docente modera el foro.');
        }

        $datos = $request->validate([
            'fijado' => ['boolean'],
            'cerrado' => ['boolean'],
        ]);

        $this->foro->moderar($tema, $datos);

        return back()->with('exito', 'Tema actualizado.');
    }

    /**
     * Borra un tema. El autor puede retirar lo suyo; el docente, cualquiera.
     */
    public function eliminarTema(Request $request, AsignaturaGrupo $materia, Actividad $actividad, ForoTema $tema): RedirectResponse
    {
        $yo = $this->autorizar($request, $materia, $actividad);
        abort_unless((int) $tema->actividad_id === $actividad->id, 404);

        $this->foro->eliminarTema($tema, $yo, $this->sala->esDocente($materia, $yo));

        return redirect("/materias/{$materia->id}/foros/{$actividad->id}")
            ->with('exito', 'Tema eliminado.');
    }

    private function temaPedido(Request $request, $temas): ?ForoTema
    {
        $pedido = (int) $request->query('tema', '0');

        return $temas->firstWhere('id', $pedido);
    }

    /** Estar en la materia, y que la actividad sea un foro de ella. */
    private function autorizar(Request $request, AsignaturaGrupo $materia, Actividad $actividad): int
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $yo = $usuario->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');

        if (! $this->sala->participa($materia, $yo)) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }

        abort_unless($actividad->curso?->asignatura_grupo_id === $materia->id, 404);
        abort_unless($actividad->tipo === TipoActividad::Foro, 404);

        // Un foro sin publicar no existe para el alumno; el docente lo prepara.
        abort_if(! $actividad->publicada && ! $this->sala->esDocente($materia, $yo), 404);

        return $yo;
    }
}
