<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Conversacion;
use App\Models\Lms\Mensaje;
use App\Services\Lms\SalaDeMateria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El chat de una materia: canal del grupo y conversaciones directas.
 *
 * Un solo controlador para el docente y el alumno. Quién eres no cambia lo que
 * el chat hace, solo con quién puedes hablar, y eso lo resuelve `SalaDeMateria`
 * a partir de la materia. Dos controladores habrían sido dos copias de la misma
 * regla de acceso, y la que se olvida de corregir es la que deja entrar.
 *
 * El alcance es la PERTENENCIA, como en el resto del LMS: estar inscrito o
 * tener la materia asignada. No hay permiso de «chatear».
 */
class ChatMateriaController extends Controller
{
    public function __construct(private readonly SalaDeMateria $sala) {}

    /** La sala: lista de conversaciones y la abierta, si hay. */
    public function show(Request $request, AsignaturaGrupo $materia): Response
    {
        $yo = $this->miPersona($request);

        if (! $this->sala->participa($materia, $yo)) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }

        // El canal del grupo existe siempre: es la puerta de entrada y no tiene
        // sentido que el primero en escribir tenga que «crearlo».
        $canal = $this->sala->canalDelGrupo($materia);

        $mias = Conversacion::query()
            ->where('asignatura_grupo_id', $materia->id)
            ->where(fn ($q) => $q->where('tipo', Conversacion::GRUPO)
                ->orWhere(fn ($s) => $s->where('persona_a_id', $yo)->orWhere('persona_b_id', $yo)))
            ->orderByDesc('ultimo_mensaje_en')
            ->get();

        $sinLeer = $this->sala->sinLeer($mias, $yo);
        $nombres = $this->nombresDe($mias->pluck('persona_a_id')->merge($mias->pluck('persona_b_id')));

        $abierta = $this->conversacionPedida($request, $mias, $canal);
        $this->sala->marcarLeida($abierta, $yo);

        return Inertia::render('Lms/Chat', [
            'materia' => [
                'id' => $materia->id,
                'nombre' => $materia->planMateria?->asignatura?->nombre ?? 'Materia',
                'abierta' => $this->sala->puedeEscribir($materia),
            ],
            'yo' => $yo,
            'soy_docente' => $this->sala->esDocente($materia, $yo),
            'volver' => $this->sala->esDocente($materia, $yo)
                ? ['href' => "/docencia/materias/{$materia->id}", 'texto' => 'La materia']
                : ['href' => "/mis-cursos/{$materia->id}", 'texto' => 'La materia'],
            'conversaciones' => $mias->map(fn (Conversacion $c) => [
                'id' => $c->id,
                'tipo' => $c->tipo,
                'titulo' => $c->esDirecta()
                    ? ($nombres[$c->contraparte($yo)] ?? 'Conversación')
                    : 'Canal del grupo',
                'ultimo_mensaje_en' => $c->ultimo_mensaje_en?->toDateTimeString(),
                // Lo abierto no se pinta como pendiente: se acaba de leer.
                'sin_leer' => $c->id === $abierta->id ? 0 : ($sinLeer[$c->id] ?? 0),
            ])->values(),
            'abierta' => [
                'id' => $abierta->id,
                'tipo' => $abierta->tipo,
                'titulo' => $abierta->esDirecta()
                    ? ($nombres[$abierta->contraparte($yo)] ?? 'Conversación')
                    : 'Canal del grupo',
            ],
            'mensajes' => $this->mensajesDe($abierta),
            // Con quién se puede abrir una directa: el alumno ve a sus docentes;
            // el docente, a sus alumnos.
            'contactos' => $this->contactos($materia, $yo),
        ]);
    }

    /** Manda un mensaje a la conversación abierta. */
    public function publicar(Request $request, AsignaturaGrupo $materia, Conversacion $conversacion): RedirectResponse
    {
        $yo = $this->miPersona($request);
        $this->exigirDeLaMateria($conversacion, $materia);

        $datos = $request->validate([
            'cuerpo' => ['required', 'string', 'max:5000'],
        ], [], ['cuerpo' => 'mensaje']);

        try {
            $this->sala->publicar($conversacion, $yo, $datos['cuerpo']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    /** Abre (o reabre) la conversación directa con alguien de la materia. */
    public function abrirDirecta(Request $request, AsignaturaGrupo $materia): RedirectResponse
    {
        $yo = $this->miPersona($request);

        if (! $this->sala->participa($materia, $yo)) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }

        $datos = $request->validate(['persona_id' => ['required', 'integer']]);

        try {
            $directa = $this->sala->directa($materia, $yo, (int) $datos['persona_id']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect("/materias/{$materia->id}/chat?conversacion={$directa->id}");
    }

    /**
     * Los mensajes nuevos desde uno dado.
     *
     * Va por `fetch` y no por Inertia: se consulta cada pocos segundos mientras
     * la sala está abierta, y una visita de Inertia recargaría toda la pantalla
     * —y cancelaría lo que el usuario esté escribiendo—.
     */
    public function nuevos(Request $request, AsignaturaGrupo $materia, Conversacion $conversacion): JsonResponse
    {
        $yo = $this->miPersona($request);
        $this->exigirDeLaMateria($conversacion, $materia);

        $desde = (int) $request->query('desde', '0');
        $nuevos = $this->mensajesDe($conversacion, $desde);

        if ($nuevos !== []) {
            $this->sala->marcarLeida($conversacion, $yo);
        }

        return response()->json(['mensajes' => $nuevos]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mensajesDe(Conversacion $conversacion, int $desde = 0): array
    {
        return Mensaje::query()
            ->with('persona:id,nombre,primer_apellido,segundo_apellido')
            ->where('conversacion_id', $conversacion->id)
            ->when($desde > 0, fn ($q) => $q->where('id', '>', $desde))
            ->orderBy('id')
            // El chat de una materia no es un archivo histórico: lo viejo se
            // consulta poco y traerlo todo hace lenta la primera carga.
            ->limit(300)
            ->get()
            ->map(fn (Mensaje $m) => [
                'id' => $m->id,
                'persona_id' => (int) $m->persona_id,
                'autor' => $this->nombreDe($m->persona),
                'cuerpo' => $m->cuerpo,
                'en' => $m->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Cuál conversación se está viendo: la pedida, si es de esta materia y mía;
     * si no, el canal del grupo.
     *
     * @param  Collection<int, Conversacion>  $mias
     */
    private function conversacionPedida(Request $request, Collection $mias, Conversacion $canal): Conversacion
    {
        $pedida = (int) $request->query('conversacion', '0');

        return $mias->firstWhere('id', $pedida) ?? $canal;
    }

    /**
     * Con quién puede abrir una directa: el alumno ve a los docentes y el
     * docente a sus alumnos. Nunca entre pares —no se pidió y traería una
     * moderación que la escuela no encargó—.
     *
     * @return array<int, array{id: int, nombre: string}>
     */
    private function contactos(AsignaturaGrupo $materia, int $yo): array
    {
        $ids = $this->sala->esDocente($materia, $yo)
            ? $this->sala->alumnos($materia)
            : $this->sala->docentes($materia);

        return Persona::query()
            ->whereIn('id', $ids->reject(fn ($id) => $id === $yo))
            ->orderBy('primer_apellido')
            ->get(['id', 'nombre', 'primer_apellido', 'segundo_apellido'])
            ->map(fn (Persona $p) => ['id' => (int) $p->id, 'nombre' => $this->nombreDe($p)])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function nombresDe(Collection $ids): array
    {
        return Persona::query()
            ->whereIn('id', $ids->filter()->unique())
            ->get(['id', 'nombre', 'primer_apellido', 'segundo_apellido'])
            ->mapWithKeys(fn (Persona $p) => [(int) $p->id => $this->nombreDe($p)])
            ->all();
    }

    private function nombreDe(?Persona $persona): string
    {
        return trim(implode(' ', array_filter([
            $persona?->nombre,
            $persona?->primer_apellido,
            $persona?->segundo_apellido,
        ]))) ?: 'Alguien';
    }

    private function exigirDeLaMateria(Conversacion $conversacion, AsignaturaGrupo $materia): void
    {
        abort_unless((int) $conversacion->asignatura_grupo_id === $materia->id, 404);

        if (! $this->sala->puedeVer($conversacion, $this->miPersona(request()))) {
            throw new AccessDeniedHttpException('Esa conversación no es tuya.');
        }
    }

    /** Sin persona no hay a quién atribuirle nada, así que se cierra. */
    private function miPersona(Request $request): int
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        return $usuario->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');
    }
}
