<?php

declare(strict_types=1);

namespace App\Http\Controllers\Permanencia;

use App\Http\Controllers\Controller;
use App\Models\ControlEscolar\Ciclo;
use App\Services\Permanencia\SenalesDelDocente;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las señales de los alumnos a los que este docente da clase.
 *
 * ── Tres capas, y la primera es de la ESCUELA ─────────────────────────────
 * El interruptor `permanencia.docente_ve_alertas` nace apagado y con él apagado
 * esta ruta responde **404**: el pedido condiciona compartir esto a «cuando la
 * política institucional lo permita», y con la pantalla existiendo por omisión
 * una escuela recién migrada le estaría enseñando a cada docente lo que el
 * sistema observó de sus alumnos sin que nadie lo hubiera decidido.
 *
 * Después el PERMISO, y después la ASIGNACIÓN — que es la que de verdad acota:
 * el permiso dice qué puede hacer, `docente_asignatura_grupo` dice sobre quién.
 */
class SenalesDelDocenteController extends Controller
{
    public function __construct(private readonly SenalesDelDocente $senales) {}

    public function index(Request $peticion): Response
    {
        $this->senales->exigirQueEsteEncendido();

        /*
         * El ciclo se elige igual que en el resto de «Mis materias»: el que
         * está en curso, o el que se pida. Y sólo los VIGENTES en el
         * desplegable: un docente no consulta lo de 2016 desde aquí, y el
         * histórico completo entierra lo de este semestre.
         */
        $ciclo = $peticion->filled('ciclo')
            ? Ciclo::find((int) $peticion->input('ciclo'))
            : Ciclo::enCurso();

        return Inertia::render('Docencia/Permanencia', [
            'datos' => $this->senales->de($peticion->user(), $ciclo?->id),
            'cicloId' => $ciclo?->id,
            'ciclos' => Ciclo::query()
                ->vigentes($ciclo?->id)
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
        ]);
    }
}
