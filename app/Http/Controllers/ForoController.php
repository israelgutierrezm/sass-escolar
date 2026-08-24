<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Entrega;
use App\Models\Lms\ForoRespuesta;
use App\Models\Lms\ForoTema;
use App\Services\Lms\SalaDeMateria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El foro de una actividad de tipo foro.
 *
 * Igual que el chat: un solo controlador para las dos partes. Lo que cambia
 * según quién eres es poder moderar —fijar, cerrar, borrar lo ajeno—, no el
 * resto.
 *
 * PARTICIPAR CUENTA COMO ENTREGAR. Al publicar su primer tema o respuesta, la
 * entrega del alumno queda registrada. Si el foro pondera, el docente lo ve en
 * su matriz junto a las tareas y le pone calificación por el mismo camino. Sin
 * esto, un foro ponderado obligaría al docente a revisar hilos por su cuenta y
 * capturar a mano quién participó.
 */
class ForoController extends Controller
{
    public function __construct(private readonly SalaDeMateria $sala) {}

    public function show(Request $request, AsignaturaGrupo $materia, Actividad $actividad): Response
    {
        $yo = $this->autorizar($request, $materia, $actividad);
        $moderador = $this->sala->esDocente($materia, $yo);

        $temas = ForoTema::query()
            ->with('autor:id,nombre,primer_apellido,segundo_apellido')
            ->where('actividad_id', $actividad->id)
            ->orderByDesc('fijado')
            ->orderByDesc('ultima_respuesta_en')
            ->orderByDesc('id')
            ->get();

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
            'temas' => $temas->map(fn (ForoTema $t) => [
                'id' => $t->id,
                'titulo' => $t->titulo,
                'autor' => $this->nombreDe($t->autor),
                'fijado' => (bool) $t->fijado,
                'cerrado' => (bool) $t->cerrado,
                'respuestas' => (int) $t->respuestas,
                'en' => $t->created_at?->toDateTimeString(),
            ])->values(),
            'abierto' => $abierto === null ? null : [
                'id' => $abierto->id,
                'titulo' => $abierto->titulo,
                'cuerpo' => $abierto->cuerpo,
                'autor' => $this->nombreDe($abierto->autor),
                'persona_id' => (int) $abierto->persona_id,
                'en' => $abierto->created_at?->toDateTimeString(),
                'fijado' => (bool) $abierto->fijado,
                'cerrado' => (bool) $abierto->cerrado,
                'respuestas' => $this->respuestasDe($abierto),
            ],
        ]);
    }

    /** Abre un tema nuevo. */
    public function crearTema(Request $request, AsignaturaGrupo $materia, Actividad $actividad): RedirectResponse
    {
        $yo = $this->autorizar($request, $materia, $actividad);

        if (! $actividad->abierta()) {
            return back()->with('error', 'Este foro ya está cerrado.');
        }

        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:200'],
            'cuerpo' => ['required', 'string', 'max:20000'],
        ], [], ['cuerpo' => 'contenido']);

        $tema = ForoTema::create([
            'actividad_id' => $actividad->id,
            'persona_id' => $yo,
            'titulo' => $datos['titulo'],
            'cuerpo' => $datos['cuerpo'],
        ]);

        $this->registrarParticipacion($actividad, $yo);

        return redirect("/materias/{$materia->id}/foros/{$actividad->id}?tema={$tema->id}");
    }

    /** Responde a un tema, o a otra respuesta. */
    public function responder(Request $request, AsignaturaGrupo $materia, Actividad $actividad, ForoTema $tema): RedirectResponse
    {
        $yo = $this->autorizar($request, $materia, $actividad);
        abort_unless((int) $tema->actividad_id === $actividad->id, 404);

        if ($tema->cerrado) {
            return back()->with('error', 'Este tema está cerrado.');
        }

        if (! $actividad->abierta()) {
            return back()->with('error', 'Este foro ya está cerrado.');
        }

        $datos = $request->validate([
            'cuerpo' => ['required', 'string', 'max:20000'],
            'responde_a_id' => ['nullable', 'integer'],
        ], [], ['cuerpo' => 'respuesta']);

        // Solo se responde a algo de ESTE tema, y solo un nivel: responder a una
        // respuesta anidada la volvería a colgar de la de primer nivel.
        $padre = $datos['responde_a_id'] === null ? null : ForoRespuesta::query()
            ->where('id', $datos['responde_a_id'])
            ->where('foro_tema_id', $tema->id)
            ->first();

        DB::transaction(function () use ($tema, $yo, $datos, $padre) {
            ForoRespuesta::create([
                'foro_tema_id' => $tema->id,
                'persona_id' => $yo,
                'responde_a_id' => $padre?->responde_a_id ?? $padre?->id,
                'cuerpo' => $datos['cuerpo'],
            ]);

            $tema->increment('respuestas');
            $tema->update(['ultima_respuesta_en' => now()]);
        });

        $this->registrarParticipacion($actividad, $yo);

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

        $tema->update($datos);

        return back()->with('exito', 'Tema actualizado.');
    }

    /**
     * Borra un tema.
     *
     * El autor puede retirar lo suyo; el docente, cualquiera. Borrar el tema se
     * lleva sus respuestas: dejarlas huérfanas sería peor que perderlas —una
     * discusión sin la pregunta no se entiende—.
     */
    public function eliminarTema(Request $request, AsignaturaGrupo $materia, Actividad $actividad, ForoTema $tema): RedirectResponse
    {
        $yo = $this->autorizar($request, $materia, $actividad);
        abort_unless((int) $tema->actividad_id === $actividad->id, 404);

        $suyo = (int) $tema->persona_id === $yo;

        if (! $suyo && ! $this->sala->esDocente($materia, $yo)) {
            throw new AccessDeniedHttpException('Ese tema no es tuyo.');
        }

        $tema->delete();

        return redirect("/materias/{$materia->id}/foros/{$actividad->id}")
            ->with('exito', 'Tema eliminado.');
    }

    /**
     * Deja constancia de que el alumno participó.
     *
     * Solo para alumnos: el docente participa moderando, no entregando. Y no se
     * pisa una entrega ya calificada —el docente calificó lo que vio, y una
     * respuesta posterior no debe borrarle la nota—.
     */
    private function registrarParticipacion(Actividad $actividad, int $personaId): void
    {
        $inscripcion = Inscripcion::query()
            ->where('inscripcion.asignatura_grupo_id', $actividad->curso?->asignatura_grupo_id)
            ->join('matricula_oferta', 'matricula_oferta.id', '=', 'inscripcion.matricula_oferta_id')
            ->where('matricula_oferta.persona_id', $personaId)
            ->select('inscripcion.*')
            ->first();

        if ($inscripcion === null) {
            return;
        }

        $entrega = Entrega::query()
            ->where('actividad_id', $actividad->id)
            ->where('inscripcion_id', $inscripcion->id)
            ->first();

        if ($entrega?->calificacion !== null) {
            return;
        }

        Entrega::actualizarOReviver(
            ['actividad_id' => $actividad->id, 'inscripcion_id' => $inscripcion->id],
            [
                'estado' => Entrega::ENTREGADA,
                'entregada_en' => now(),
                'tarde' => $actividad->cierra_en !== null && now()->gt($actividad->cierra_en),
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function respuestasDe(ForoTema $tema): array
    {
        $todas = ForoRespuesta::query()
            ->with('autor:id,nombre,primer_apellido,segundo_apellido')
            ->where('foro_tema_id', $tema->id)
            ->orderBy('id')
            ->get();

        // Un solo nivel: las de primer nivel con las suyas colgando.
        return $todas->whereNull('responde_a_id')
            ->map(fn (ForoRespuesta $r) => [
                'id' => $r->id,
                'autor' => $this->nombreDe($r->autor),
                'persona_id' => (int) $r->persona_id,
                'cuerpo' => $r->cuerpo,
                'en' => $r->created_at?->toDateTimeString(),
                'hijas' => $todas->where('responde_a_id', $r->id)
                    ->map(fn (ForoRespuesta $h) => [
                        'id' => $h->id,
                        'autor' => $this->nombreDe($h->autor),
                        'persona_id' => (int) $h->persona_id,
                        'cuerpo' => $h->cuerpo,
                        'en' => $h->created_at?->toDateTimeString(),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function temaPedido(Request $request, $temas): ?ForoTema
    {
        $pedido = (int) $request->query('tema', '0');

        return $temas->firstWhere('id', $pedido);
    }

    private function nombreDe(?Persona $persona): string
    {
        return trim(implode(' ', array_filter([
            $persona?->nombre,
            $persona?->primer_apellido,
            $persona?->segundo_apellido,
        ]))) ?: 'Alguien';
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
