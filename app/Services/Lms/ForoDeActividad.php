<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Persona;
use App\Models\Lms\Actividad;
use App\Models\Lms\Entrega;
use App\Models\Lms\ForoRespuesta;
use App\Models\Lms\ForoTema;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El foro de una actividad de tipo foro: leer los temas, participar y moderar.
 *
 * Vive aquí y no en el controlador porque la web (`ForoController`) y la app lo
 * comparten: escrito dos veces, un lado acabaría dejando de registrar la
 * participación —lo que convierte el foro en una entrega calificable— o
 * anidando las respuestas a dos niveles. La AUTORIZACIÓN (estar en la materia,
 * ser el docente) la resuelve cada entrada a su modo; aquí llegan la persona ya
 * resuelta y, cuando importa, si es moderadora.
 *
 * Los guardas suaves —foro cerrado, tema cerrado— DEVUELVEN su motivo en vez de
 * lanzar, como `EntregaDeActividad`: la web lo enseña como aviso y la app como
 * 422. El candado de prerrequisito sí lanza (403), porque es la misma puerta
 * dura en los dos.
 */
class ForoDeActividad
{
    public function __construct(private readonly Prerequisitos $prerequisitos) {}

    /** Los temas del foro, ordenados: primero los fijados, luego los recientes. */
    public function temas(Actividad $actividad): Collection
    {
        return ForoTema::query()
            ->with('autor:id,nombre,primer_apellido,segundo_apellido')
            ->where('actividad_id', $actividad->id)
            ->orderByDesc('fijado')
            ->orderByDesc('ultima_respuesta_en')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, ForoTema>  $temas
     * @return array<int, array<string, mixed>>
     */
    public function listaTemas(Collection $temas): array
    {
        return $temas->map(fn (ForoTema $t) => [
            'id' => $t->id,
            'titulo' => $t->titulo,
            'autor' => $this->nombreDe($t->autor),
            'fijado' => (bool) $t->fijado,
            'cerrado' => (bool) $t->cerrado,
            'respuestas' => (int) $t->respuestas,
            'en' => $t->created_at?->toDateTimeString(),
        ])->values()->all();
    }

    /**
     * El tema abierto con sus respuestas (un solo nivel de anidado).
     *
     * @return array<string, mixed>|null
     */
    public function detalle(?ForoTema $tema): ?array
    {
        if ($tema === null) {
            return null;
        }

        return [
            'id' => $tema->id,
            'titulo' => $tema->titulo,
            'cuerpo' => $tema->cuerpo,
            'autor' => $this->nombreDe($tema->autor),
            'persona_id' => (int) $tema->persona_id,
            'en' => $tema->created_at?->toDateTimeString(),
            'fijado' => (bool) $tema->fijado,
            'cerrado' => (bool) $tema->cerrado,
            'respuestas' => $this->respuestasDe($tema),
        ];
    }

    /**
     * Abre un tema. El guarda de foro cerrado devuelve su motivo; el candado de
     * avance lanza 403.
     *
     * @return array{error: string|null, tema: ForoTema|null}
     */
    public function crearTema(Actividad $actividad, int $personaId, string $titulo, string $cuerpo): array
    {
        if (! $actividad->abierta()) {
            return ['error' => 'Este foro ya está cerrado.', 'tema' => null];
        }

        // Sólo el alumno tiene inscripción; el docente pasa por la puerta por persona.
        $this->prerequisitos->exigirDesbloqueadaParaPersona($actividad, $personaId);

        $tema = ForoTema::create([
            'actividad_id' => $actividad->id,
            'persona_id' => $personaId,
            'titulo' => $titulo,
            'cuerpo' => $cuerpo,
        ]);

        $this->registrarParticipacion($actividad, $personaId);

        return ['error' => null, 'tema' => $tema];
    }

    /**
     * Responde a un tema, o a una respuesta de primer nivel de ese tema.
     *
     * @return array{error: string|null}
     */
    public function responder(Actividad $actividad, ForoTema $tema, int $personaId, string $cuerpo, ?int $respondeAId): array
    {
        if ($tema->cerrado) {
            return ['error' => 'Este tema está cerrado.'];
        }

        if (! $actividad->abierta()) {
            return ['error' => 'Este foro ya está cerrado.'];
        }

        $this->prerequisitos->exigirDesbloqueadaParaPersona($actividad, $personaId);

        // Sólo se responde a algo de ESTE tema, y sólo un nivel: responder a una
        // anidada la vuelve a colgar de la de primer nivel.
        $padre = $respondeAId === null ? null : ForoRespuesta::query()
            ->where('id', $respondeAId)
            ->where('foro_tema_id', $tema->id)
            ->first();

        DB::transaction(function () use ($tema, $personaId, $cuerpo, $padre) {
            ForoRespuesta::create([
                'foro_tema_id' => $tema->id,
                'persona_id' => $personaId,
                'responde_a_id' => $padre?->responde_a_id ?? $padre?->id,
                'cuerpo' => $cuerpo,
            ]);

            $tema->increment('respuestas');
            $tema->update(['ultima_respuesta_en' => now()]);
        });

        $this->registrarParticipacion($actividad, $personaId);

        return ['error' => null];
    }

    /** Fijar o cerrar un tema (sólo el docente; la puerta la pone el llamador). */
    public function moderar(ForoTema $tema, array $datos): void
    {
        $tema->update($datos);
    }

    /**
     * Borra un tema. El autor retira lo suyo; el moderador, cualquiera. Borrar el
     * tema se lleva sus respuestas: una discusión sin la pregunta no se entiende.
     */
    public function eliminarTema(ForoTema $tema, int $personaId, bool $moderador): void
    {
        $suyo = (int) $tema->persona_id === $personaId;
        AvisoParaElUsuario::aMenosQue($suyo || $moderador, 403, 'Ese tema no es tuyo.');

        $tema->delete();
    }

    /**
     * Deja constancia de que el alumno participó: al publicar, su entrega queda
     * registrada para que el foro ponderado se califique como una tarea.
     *
     * Sólo para alumnos (el docente participa moderando, no entregando) y sin
     * pisar una entrega ya calificada —el docente calificó lo que vio, y una
     * respuesta posterior no le borra la nota—.
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

    /** @return array<int, array<string, mixed>> */
    private function respuestasDe(ForoTema $tema): array
    {
        $todas = ForoRespuesta::query()
            ->with('autor:id,nombre,primer_apellido,segundo_apellido')
            ->where('foro_tema_id', $tema->id)
            ->orderBy('id')
            ->get();

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

    private function nombreDe(?Persona $persona): string
    {
        return trim(implode(' ', array_filter([
            $persona?->nombre,
            $persona?->primer_apellido,
            $persona?->segundo_apellido,
        ]))) ?: 'Alguien';
    }
}
