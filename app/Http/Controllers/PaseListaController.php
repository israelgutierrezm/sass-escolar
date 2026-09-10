<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Services\Asistencia\PaseDeLista;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pase de lista de una materia.
 *
 * La tabla `asistencia_clase` existía desde el módulo de asistencia y el portal
 * del alumno ya la leía, pero nadie podía ESCRIBIRLA: había permiso
 * (`pasar-lista`) y no había ni controlador ni pantalla. Esto es esa mitad.
 *
 * Se guarda la lista COMPLETA de una sesión de una sola vez, no alumno por
 * alumno: pasar lista es un acto único sobre el grupo, y guardar de a uno
 * dejaría sesiones a medias si el docente se distrae a la mitad.
 */
class PaseListaController extends Controller
{
    use AutorizaMateriaPropia;

    public function __construct(private readonly PaseDeLista $pase) {}

    public function guardar(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);

        $datos = $request->validate([
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'modalidad' => ['required', Rule::in(PaseDeLista::MODALIDADES)],
            'asistencias' => ['required', 'array', 'min:1'],
            'asistencias.*.inscripcion_id' => ['required', 'integer'],
            'asistencias.*.estatus' => ['required', Rule::in(PaseDeLista::ESTATUS)],
            'asistencias.*.observacion' => ['nullable', 'string', 'max:300'],
        ], [
            'fecha.before_or_equal' => 'No se puede pasar lista de una clase que todavía no ocurre.',
        ], ['asistencias' => 'lista']);

        /** @var Usuario $usuario */
        $usuario = $request->user();

        // La escritura vive en el servicio (una sola verdad con la app móvil):
        // sólo alumnos de esta materia, y repasar el mismo día corrige sin
        // duplicar reviviendo la fila borrada.
        $guardadas = $this->pase->guardar(
            $asignaturaGrupo,
            $datos['fecha'],
            $datos['modalidad'],
            $datos['asistencias'],
            $usuario->persona_id,
        );

        $cual = $datos['modalidad'] === 'unica' ? '' : " ({$datos['modalidad']})";

        return back()->with('exito', "Lista del {$datos['fecha']}{$cual} guardada: {$guardadas} alumno(s).");
    }

    /** Activa o desactiva el segundo pase de lista de esta materia. */
    public function alternarDoble(Request $request, AsignaturaGrupo $asignaturaGrupo): RedirectResponse
    {
        $this->autorizar($request, $asignaturaGrupo);

        $activar = $request->boolean('doble_pase_lista');

        // Apagarlo con registros de práctica ya tomados los dejaría invisibles
        // y sin forma de corregirlos: el dato existe pero la pantalla ya no lo
        // muestra. Mejor decirlo que esconderlo.
        if (! $activar) {
            $registrosPractica = AsistenciaClase::query()
                ->where('modalidad', 'practica')
                ->whereIn('inscripcion_id', Inscripcion::where('asignatura_grupo_id', $asignaturaGrupo->id)->pluck('id'))
                ->count();

            if ($registrosPractica > 0) {
                return back()->with(
                    'error',
                    "No se puede quitar el segundo pase de lista: ya hay {$registrosPractica} registro(s) de práctica. "
                    .'Bórralos primero si de verdad quieres dejar uno solo.',
                );
            }
        }

        $asignaturaGrupo->update(['doble_pase_lista' => $activar]);

        return back()->with(
            'exito',
            $activar
                ? 'Esta materia pasa lista por separado en teoría y práctica.'
                : 'Esta materia pasa lista una sola vez por sesión.',
        );
    }

    /** Solo se pasa lista en una materia propia; control escolar entra a todas. */
    private function autorizar(Request $request, AsignaturaGrupo $asignaturaGrupo): void
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
    }
}
