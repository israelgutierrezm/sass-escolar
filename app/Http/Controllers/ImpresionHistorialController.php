<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Historial\HistorialImprimible;
use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\DisenoHistorial;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

/**
 * El historial académico impreso, por sus dos puertas.
 *
 * ── Dos entradas y no una ─────────────────────────────────────────────────
 * Control escolar lo imprime de CUALQUIER matrícula desde el expediente, y el
 * alumno imprime la SUYA desde su portal. Una sola ruta con un id de matrícula
 * habría servido para las dos, y ahí está el problema: la del alumno no puede
 * recibir id de nadie —si lo recibe, tarde o temprano alguien escribe otro—, y
 * la suya sale de la sesión.
 *
 * ── Y salen distintas ─────────────────────────────────────────────────────
 * La de ventanilla es el documento a secas; la del alumno lleva marca de agua
 * si la escuela así lo pidió, porque es una copia sin sello ni firma autógrafa
 * y conviene que el papel lo diga. Que el alumno pueda descargarlo siquiera es
 * un interruptor de la escuela: hay planteles donde el historial sólo se
 * entrega en ventanilla.
 */
class ImpresionHistorialController extends Controller
{
    use AlcanceDelAlumno;

    public function __construct(private readonly HistorialImprimible $imprimible) {}

    /** La de control escolar: cualquier matrícula, sin marca de agua. */
    public function deControlEscolar(MatriculaOferta $matricula): Renderable
    {
        return $this->documento($matricula, conMarcaDeAgua: false);
    }

    /**
     * La del alumno: la suya, y sólo si la escuela dejó abierta la descarga.
     *
     * La matrícula sale de la sesión. Quien estudia dos carreras elige entre
     * LAS SUYAS y la elección se busca en esa misma lista, así que un id ajeno
     * no encuentra pareja y cae a la propia — la misma salvaguarda que la
     * pantalla del historial y la de la credencial.
     */
    public function delAlumno(Request $peticion): Renderable
    {
        $matriculas = $this->misMatriculas($peticion);

        abort_if($matriculas->isEmpty(), 404);

        $elegida = $matriculas->firstWhere('id', $peticion->integer('matricula')) ?? $matriculas->first();

        $diseno = $this->disenoDe($elegida);

        // 404 y no 403: si la escuela no ofrece la descarga, esa página no
        // existe para el alumno. Un 403 le diría que existe y que no le toca,
        // que es una conversación que nadie va a poder resolver en ventanilla.
        abort_unless($diseno->descarga_alumno, 404);

        return $this->documento($elegida, conMarcaDeAgua: $diseno->marca_agua_alumno, diseno: $diseno);
    }

    private function documento(MatriculaOferta $matricula, bool $conMarcaDeAgua, ?DisenoHistorial $diseno = null): Renderable
    {
        return view(
            'impresion.historial',
            $this->imprimible->armar($matricula, $diseno ?? $this->disenoDe($matricula), $conMarcaDeAgua),
        );
    }

    /** El diseño del nivel de esta carrera, o el general. */
    private function disenoDe(MatriculaOferta $matricula): DisenoHistorial
    {
        $matricula->loadMissing('oferta.carrera:id,nombre,nivel_estudios_id');

        return DisenoHistorial::paraNivel($matricula->oferta?->carrera?->nivel_estudios_id);
    }
}
