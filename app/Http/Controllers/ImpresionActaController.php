<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actas\ActaImprimible;
use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

/**
 * El acta de calificaciones, lista para imprimir.
 *
 * ── Cuelga de la materia y no del acta suelta ─────────────────────────────
 * La ruta lleva las dos, `/captura/{materia}/actas/{acta}/imprimir`, y se
 * comprueba que el acta sea DE esa materia. Con sólo el id del acta, el alcance
 * del docente —que se resuelve sobre la materia— habría que volver a deducirlo
 * desde el acta, y eso es una segunda forma de contestar la misma pregunta de
 * acceso. La que ya existe vive en `AutorizaMateriaPropia`.
 *
 * ── Sólo se imprime lo FIRMADO ────────────────────────────────────────────
 * Un acta abierta lleva un folio `BORRADOR-…` que no es folio de nada: el real
 * se emite al cerrar. Imprimirla daría un papel con aspecto oficial y un número
 * inventado, que es peor que no poder imprimirla. Responde 404 y no 403 porque
 * ese documento todavía no existe — no es que no le toque a quien lo pide.
 *
 * ── En Blade, como el historial ───────────────────────────────────────────
 * El proyecto no tiene librería de PDF y el navegador ya sabe imprimir. Meter
 * un motor de maquetación entero para producir lo que `Ctrl+P → Guardar como
 * PDF` hace igual sería cargar con sus fuentes y sus rarezas de saltos de
 * página a cambio de nada.
 */
class ImpresionActaController extends Controller
{
    use AutorizaMateriaPropia;

    public function __construct(private readonly ActaImprimible $imprimible) {}

    public function __invoke(Request $peticion, AsignaturaGrupo $asignaturaGrupo, Acta $acta): Renderable
    {
        $this->autorizarMateriaPropia($peticion, $asignaturaGrupo);

        // Que el acta sea de ESTA materia: sin esto, el id del acta sería una
        // puerta lateral para leer la de cualquier grupo con sólo tener una
        // materia propia con la que abrir la ruta.
        abort_unless($acta->asignatura_grupo_id === $asignaturaGrupo->id, 404);

        abort_unless($acta->estaCerrada(), 404);

        return view('impresion.acta', $this->imprimible->armar($acta));
    }
}
