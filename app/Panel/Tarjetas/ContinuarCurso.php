<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\ActividadVista;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Panel\TarjetaPanel;

/**
 * Por dónde va en cada curso, con la puerta para seguir.
 *
 * ── Retomar sin buscar ─────────────────────────────────────────────────────
 * El aula ya sabe abrir en la primera lección sin completar, pero para llegar
 * ahí hay que entrar a «Mis cursos», elegir la materia y pulsar continuar. Esta
 * tarjeta se salta los tres pasos: es el equivalente del «seguir viendo» de
 * cualquier plataforma de cursos.
 *
 * ── Lo empezado primero ────────────────────────────────────────────────────
 * Arriba lo que tiene avance y no está terminado —que es lo que uno retoma—;
 * después lo intacto. Un curso al 90 % y otro sin abrir no piden lo mismo.
 */
class ContinuarCurso implements TarjetaPanel
{
    public function clave(): string
    {
        return 'continuar-curso';
    }

    public function titulo(): string
    {
        return 'Continuar donde me quedé';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-cursos';
    }

    public function tipo(): string
    {
        return 'barras';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z';
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
            ->get();

        if ($inscripciones->isEmpty()) {
            return null;
        }

        $cursos = Curso::query()
            ->whereIn('asignatura_grupo_id', $inscripciones->pluck('asignatura_grupo_id'))
            ->pluck('asignatura_grupo_id', 'id');

        if ($cursos->isEmpty()) {
            return null;
        }

        $actividades = Actividad::query()
            ->visibles()
            ->whereIn('curso_id', $cursos->keys())
            ->get(['id', 'curso_id', 'tipo']);

        if ($actividades->isEmpty()) {
            return null;
        }

        // Mismo criterio que el aula: lo que se entrega lo declara la entrega;
        // la lectura, el botón del alumno. Si divergieran, la barra del panel y
        // la del aula dirían cosas distintas del mismo curso.
        $entregadas = Entrega::query()
            ->whereIn('inscripcion_id', $inscripciones->pluck('id'))
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('entregada_en')
            ->get(['actividad_id', 'inscripcion_id'])
            ->map(fn (Entrega $e) => "{$e->actividad_id}-{$e->inscripcion_id}")
            ->flip();

        $completadas = ActividadVista::query()
            ->whereIn('inscripcion_id', $inscripciones->pluck('id'))
            ->whereIn('actividad_id', $actividades->pluck('id'))
            ->whereNotNull('completada_en')
            ->get(['actividad_id', 'inscripcion_id'])
            ->map(fn (ActividadVista $v) => "{$v->actividad_id}-{$v->inscripcion_id}")
            ->flip();

        $porCurso = $actividades->groupBy('curso_id');

        $series = [];

        foreach ($inscripciones as $inscripcion) {
            $cursoId = $cursos->search($inscripcion->asignatura_grupo_id);

            if ($cursoId === false) {
                continue;
            }

            $delCurso = $porCurso->get($cursoId) ?? collect();

            if ($delCurso->isEmpty()) {
                continue;
            }

            $hechas = $delCurso->filter(function (Actividad $a) use ($inscripcion, $entregadas, $completadas) {
                $llave = "{$a->id}-{$inscripcion->id}";

                return $a->tipo->seEntrega() ? $entregadas->has($llave) : $completadas->has($llave);
            })->count();

            $porcentaje = (int) round($hechas * 100 / $delCurso->count());

            $series[] = [
                'etiqueta' => $inscripcion->asignaturaGrupo?->planMateria?->asignatura?->nombre ?? 'Materia',
                'valor' => $porcentaje,
                'enlace' => '/mis-cursos/'.$inscripcion->asignatura_grupo_id.'/aula',
                // Para ordenar: lo empezado y sin terminar es lo que se retoma.
                'empezado' => $hechas > 0 && $porcentaje < 100,
            ];
        }

        if ($series === []) {
            return null;
        }

        $ordenadas = collect($series)
            ->sortByDesc(fn (array $s) => ($s['empezado'] ? 1000 : 0) + $s['valor'])
            ->map(fn (array $s) => ['etiqueta' => $s['etiqueta'], 'valor' => $s['valor'], 'enlace' => $s['enlace']])
            ->take(5)
            ->values()
            ->all();

        return [
            'series' => $ordenadas,
            'pie' => 'El porcentaje es lo recorrido del contenido, no tu calificación.',
        ];
    }
}
