<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Entrega;
use App\Panel\TarjetaPanel;

/**
 * Lo que le acaban de calificar.
 *
 * ── Por qué importa ────────────────────────────────────────────────────────
 * Es la otra mitad de entregar. El alumno manda un trabajo y después no tiene
 * forma de enterarse de que ya se lo revisaron salvo entrando materia por
 * materia a buscar: la nota aparecía en silencio, y con ella la
 * retroalimentación que el docente se tomó el trabajo de escribir.
 *
 * ── Las últimas, no todas ──────────────────────────────────────────────────
 * Cinco. El historial completo es el kárdex; aquí lo que se contesta es «¿hay
 * algo nuevo?», y para eso sobra con lo reciente.
 */
class MisCalificacionesRecientes implements TarjetaPanel
{
    public function clave(): string
    {
        return 'mis-calificaciones';
    }

    public function titulo(): string
    {
        return 'Calificaciones recientes';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-cursos';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        $inscripciones = Inscripcion::query()
            ->whereIn(
                'matricula_oferta_id',
                MatriculaOferta::query()->where('persona_id', $usuario->persona_id)->select('id'),
            )
            ->with('asignaturaGrupo.planMateria.asignatura:id,nombre')
            ->get()
            ->keyBy('id');

        if ($inscripciones->isEmpty()) {
            return null;
        }

        $entregas = Entrega::query()
            ->whereIn('inscripcion_id', $inscripciones->keys())
            ->whereNotNull('calificacion')
            ->with('actividad:id,titulo,puntos')
            // Por cuándo se calificó, no por cuándo se entregó: lo que el alumno
            // quiere ver primero es lo último que le revisaron.
            ->orderByDesc('calificada_en')
            ->limit(5)
            ->get();

        if ($entregas->isEmpty()) {
            return null;
        }

        return [
            'renglones' => $entregas->map(function (Entrega $e) use ($inscripciones) {
                $materia = $inscripciones->get($e->inscripcion_id)
                    ?->asignaturaGrupo?->planMateria?->asignatura?->nombre;

                $sobre = (float) ($e->actividad?->puntos ?? 0);

                return [
                    'etiqueta' => $e->actividad?->titulo ?? 'Actividad',
                    // La retroalimentación se anuncia: es lo que hace que el
                    // alumno entre a leerla en vez de quedarse con el número.
                    'detalle' => trim(implode(' · ', array_filter([
                        $materia,
                        filled($e->retroalimentacion) ? 'con comentarios' : null,
                    ]))),
                    'valor' => $sobre > 0
                        ? rtrim(rtrim(number_format((float) $e->calificacion, 2), '0'), '.').' / '.(int) $sobre
                        : (string) $e->calificacion,
                    'enlace' => $this->enlaceA($e, $inscripciones),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Inscripcion>  $inscripciones
     */
    private function enlaceA(Entrega $e, $inscripciones): ?string
    {
        $materiaId = $inscripciones->get($e->inscripcion_id)?->asignatura_grupo_id;

        // A la lección, que es donde está su trabajo y lo que le respondieron.
        return $materiaId === null ? null : "/mis-cursos/{$materiaId}/aula/{$e->actividad_id}";
    }
}
