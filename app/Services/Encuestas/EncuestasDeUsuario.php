<?php

declare(strict_types=1);

namespace App\Services\Encuestas;

use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Participacion;
use App\Models\Encuestas\Respuesta;
use App\Models\Encuestas\Sujeto;
use App\Models\Identidad\Usuario;
use App\Services\Plataforma\AlcanceDeDestinos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué encuestas tiene pendientes una persona y cómo se contestan.
 *
 * ── El pendiente no es la encuesta, es cada sujeto ─────────────────────────
 * En una evaluación docente el alumno no tiene «una» encuesta pendiente: tiene
 * una por cada docente que le da clase. Se listan por separado porque así es
 * como se contestan y como se cuenta el avance; agruparlas en un solo pendiente
 * daría la sensación de que se acaba con un envío.
 *
 * ── Sólo los suyos ─────────────────────────────────────────────────────────
 * Un alumno evalúa a los docentes de las materias EN LAS QUE ESTÁ INSCRITO, no
 * a todos los de la aplicación. Preguntarle por un profesor que no conoce no
 * produce un dato malo: produce un dato inventado, que es peor porque se
 * promedia con los buenos.
 */
class EncuestasDeUsuario
{
    public function __construct(private readonly AlcanceDeDestinos $alcance) {}

    /**
     * Lo que le falta por contestar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendientes(Usuario $usuario): array
    {
        if ($usuario->persona_id === null) {
            return [];
        }

        $pendientes = [];

        foreach ($this->suyas($usuario)->with('encuesta')->get() as $aplicacion) {
            foreach ($this->sujetosPara($aplicacion, $usuario) as $sujeto) {
                if ($this->yaContesto($aplicacion->id, $sujeto?->id, $usuario->persona_id)) {
                    continue;
                }

                $pendientes[] = [
                    'aplicacion_id' => $aplicacion->id,
                    'sujeto_id' => $sujeto?->id,
                    'titulo' => $aplicacion->titulo,
                    'instrucciones' => $aplicacion->instrucciones,
                    'obligatoria' => $aplicacion->obligatoria,
                    'anonima' => $aplicacion->anonima,
                    'cierra_en' => $aplicacion->cierra_en?->toDateTimeString(),
                    // De quién es la encuesta, cuando evalúa a alguien.
                    'sujeto' => $sujeto === null ? null : [
                        'docente' => $sujeto->persona?->nombreCompleto(),
                        'materia' => $sujeto->materia?->planMateria?->asignatura?->nombre,
                        'grupo' => $sujeto->materia?->grupo?->clave,
                        'papel' => $sujeto->papel,
                    ],
                ];
            }
        }

        return $pendientes;
    }

    /** Cuántas le faltan, para el contador de la barra. */
    public function cuantasPendientes(Usuario $usuario): int
    {
        return count($this->pendientes($usuario));
    }

    /**
     * Las obligatorias sin contestar, que son las que se interponen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bloqueantes(Usuario $usuario): array
    {
        return array_values(array_filter($this->pendientes($usuario), fn (array $p) => $p['obligatoria']));
    }

    /**
     * Guarda lo contestado.
     *
     * En una transacción y en dos tablas que no se conocen: la constancia de
     * que esta persona participó, y las respuestas sin dueño. Si algo falla, no
     * puede quedar una sin la otra —o el alumno no podría volver a contestar,
     * o contestaría dos veces—.
     *
     * @param  array<int, array<string, mixed>>  $respuestas  pregunta_id => valor
     */
    public function guardar(Usuario $usuario, AplicacionEncuesta $aplicacion, ?Sujeto $sujeto, array $respuestas): bool
    {
        if (! $this->leToca($usuario, $aplicacion, $sujeto)) {
            return false;
        }

        if ($this->yaContesto($aplicacion->id, $sujeto?->id, $usuario->persona_id)) {
            return false;
        }

        DB::transaction(function () use ($usuario, $aplicacion, $sujeto, $respuestas) {
            Participacion::create([
                'aplicacion_id' => $aplicacion->id,
                'sujeto_id' => $sujeto?->id,
                'persona_id' => $usuario->persona_id,
                'respondido_en' => now(),
            ]);

            $respuesta = Respuesta::create([
                'aplicacion_id' => $aplicacion->id,
                'sujeto_id' => $sujeto?->id,
                // Contexto para segmentar los resultados, no para identificar:
                // el rol con el que opera y su campus.
                'rol_id' => $usuario->rol_activo_id,
                'campus_id' => $this->campusDe($usuario),
                'enviada_en' => now(),
            ]);

            foreach ($respuestas as $preguntaId => $valor) {
                foreach ($this->itemsDe((int) $preguntaId, $valor) as $item) {
                    $respuesta->items()->create($item);
                }
            }
        });

        return true;
    }

    /**
     * Un valor capturado se convierte en uno o varios renglones.
     *
     * La opción múltiple da uno por opción marcada: es lo que permite contarlas
     * después sin desarmar un JSON.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemsDe(int $preguntaId, mixed $valor): array
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return [];
        }

        if (is_array($valor)) {
            return array_map(
                fn ($opcion) => ['pregunta_id' => $preguntaId, 'opcion_id' => (int) $opcion],
                $valor,
            );
        }

        // Un número es una escala o una cantidad; lo demás, texto. La opción
        // única llega como id numérico, así que se guarda en las dos: el id en
        // `opcion_id` y el valor en `numero` sólo cuando no hay opción.
        return [['pregunta_id' => $preguntaId, ...$this->columnaDe($valor)]];
    }

    /** @return array<string, mixed> */
    private function columnaDe(mixed $valor): array
    {
        if (is_numeric($valor)) {
            return ['numero' => (float) $valor];
        }

        return ['texto' => (string) $valor];
    }

    /** Las aplicaciones abiertas que alcanzan a este usuario. */
    private function suyas(Usuario $usuario): Builder
    {
        return AplicacionEncuesta::query()
            ->abiertas()
            ->where(fn (Builder $q) => $this->alcance->aplicar($q, $usuario));
    }

    /**
     * Los sujetos que ESTA persona puede evaluar.
     *
     * En una general no hay sujeto: se devuelve un único nulo, que es «la
     * encuesta, sin más». En una docente, sólo los de las materias en las que
     * está inscrito.
     *
     * @return Collection<int, Sujeto|null>
     */
    private function sujetosPara(AplicacionEncuesta $aplicacion, Usuario $usuario): Collection
    {
        if (! $aplicacion->esDocente()) {
            return collect([null]);
        }

        return Sujeto::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->whereIn('asignatura_grupo_id', $this->materiasDe($usuario))
            ->with(['persona', 'materia.planMateria.asignatura', 'materia.grupo'])
            ->get();
    }

    /**
     * Las materias-grupo en las que la persona está inscrita.
     *
     * @return array<int, int>
     */
    private function materiasDe(Usuario $usuario): array
    {
        return DB::table('inscripcion')
            ->join('matricula_oferta', 'matricula_oferta.id', '=', 'inscripcion.matricula_oferta_id')
            ->where('matricula_oferta.persona_id', $usuario->persona_id)
            ->distinct()
            ->pluck('inscripcion.asignatura_grupo_id')
            ->all();
    }

    private function leToca(Usuario $usuario, AplicacionEncuesta $aplicacion, ?Sujeto $sujeto): bool
    {
        if ($usuario->persona_id === null || ! $aplicacion->estaAbierta()) {
            return false;
        }

        if (! $this->suyas($usuario)->whereKey($aplicacion->id)->exists()) {
            return false;
        }

        if ($sujeto === null) {
            return ! $aplicacion->esDocente();
        }

        return $sujeto->aplicacion_id === $aplicacion->id
            && in_array($sujeto->asignatura_grupo_id, $this->materiasDe($usuario), true);
    }

    private function yaContesto(int $aplicacionId, ?int $sujetoId, int $personaId): bool
    {
        return Participacion::query()
            ->where('aplicacion_id', $aplicacionId)
            ->where('persona_id', $personaId)
            ->when($sujetoId === null,
                fn (Builder $q) => $q->whereNull('sujeto_id'),
                fn (Builder $q) => $q->where('sujeto_id', $sujetoId),
            )
            ->exists();
    }

    private function campusDe(Usuario $usuario): ?int
    {
        return DB::table('persona_rol')
            ->where('persona_id', $usuario->persona_id)
            ->where('activo', true)
            ->whereNotNull('campus_id')
            ->value('campus_id');
    }
}
