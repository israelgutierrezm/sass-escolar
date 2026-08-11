<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\PlanEstudio;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Grupo;
use App\Models\Identidad\Rol;
use App\Models\Academico\NivelEstudio;

/**
 * Los catálogos que alimentan el selector de destinos.
 *
 * Lo comparten el calendario y los avisos: los dos dirigen a la misma escuela
 * con los mismos criterios —rol, campus, nivel, carrera, plan, grupo, materia,
 * alumno— y tener dos listas sería garantizar que un día una ofrezca un campus
 * que la otra no.
 */
trait ArmaDestinos
{
    /**
     * Los catálogos con los que se arma la segmentación.
     *
     * @return array<string, mixed>
     */
    protected function opcionesDeDestino(): array
    {
        return [
            // Por su `nombre` y no por su `name`: la clave técnica
            // —«padre_familia»— es lo que el sistema usa por dentro, no lo que
            // quien dirige un aviso tiene que reconocer en una lista.
            'rol' => Rol::query()->orderBy('nombre')->get(['id', 'nombre', 'name'])
                ->map(fn (Rol $r) => ['id' => $r->id, 'nombre' => $r->nombre ?: $r->name])->values(),

            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->values(),

            // Los niveles viven en la base CENTRAL: el modelo lleva
            // `CentralConnection` y una consulta cruda contra el tenant no los
            // encuentra. Es la trampa que documenta CLAUDE.md sobre catálogos
            // universales.
            'nivel' => NivelEstudio::query()->activos()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn ($n) => ['id' => $n->id, 'nombre' => $n->nombre])->values(),

            'carrera' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre', 'clave'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => "{$c->nombre} ({$c->clave})"])->values(),

            'plan' => PlanEstudio::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre])->values(),

            'grupo' => Grupo::query()->with('ciclo:id,clave')->orderByDesc('id')->limit(300)->get(['id', 'clave', 'ciclo_id'])
                ->map(fn ($g) => ['id' => $g->id, 'nombre' => "{$g->clave} · {$g->ciclo?->clave}"])->values(),

            'materia' => AsignaturaGrupo::query()
                ->with(['planMateria.asignatura:id,nombre', 'grupo:id,clave'])
                ->orderByDesc('id')->limit(300)->get(['id', 'plan_materia_id', 'grupo_id'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'nombre' => ($m->planMateria?->asignatura?->nombre ?? 'Materia').' · '.($m->grupo?->clave ?? ''),
                ])->values(),

            /*
             * Los alumnos NO se mandan: son miles y no caben en un `select`.
             * El componente los busca contra el servidor conforme se escribe,
             * que es como ya funciona el buscador de personas del sistema.
             */
            'alumno' => [],
        ];
    }
}
