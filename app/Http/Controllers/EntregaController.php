<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\EntregaArchivo;
use App\Services\Lms\EntregaDeActividad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que el alumno entrega de una actividad.
 *
 * El alcance es la PERTENENCIA: se busca la inscripción de quien entró en la
 * materia de esa actividad. Si no la tiene, la actividad no existe para él —y
 * la respuesta es la misma que si no existiera, para que probar ids no revele
 * qué actividades hay—.
 */
class EntregaController extends Controller
{
    use AlcanceDelAlumno;

    public function __construct(private readonly EntregaDeActividad $entregas) {}

    public function guardar(Request $request, Actividad $actividad): RedirectResponse
    {
        $inscripcion = $this->miInscripcionEn($request, $actividad);

        AvisoParaElUsuario::si($inscripcion === null, 403, 'Esa actividad no es de una materia que curses.');

        $datos = $request->validate([
            'contenido' => ['nullable', 'string', 'max:20000'],
            'archivos' => ['nullable', 'array', 'max:5'],
            'archivos.*' => ['file', 'max:20480'],
        ], [], ['contenido' => 'respuesta']);

        ['error' => $error, 'entrega' => $entrega] = $this->entregas->entregar(
            $actividad,
            $inscripcion,
            $datos['contenido'] ?? null,
            $request->file('archivos', []),
        );

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return back()->with(
            'exito',
            $entrega->tarde
                ? 'Entrega registrada, marcada como fuera de tiempo.'
                : 'Entrega registrada.',
        );
    }

    /** Descarga de un adjunto propio (o del docente de la materia). */
    public function archivo(Request $request, EntregaArchivo $archivo)
    {
        $entrega = $archivo->entrega()->with('actividad.curso')->firstOrFail();
        $asignaturaGrupoId = $entrega->actividad?->curso?->asignatura_grupo_id;

        $mio = Inscripcion::query()
            ->whereKey($entrega->inscripcion_id)
            ->whereIn('matricula_oferta_id', $this->misMatriculas($request)->pluck('id'))
            ->exists();

        $soyDocente = $request->user()->persona_id !== null && $asignaturaGrupoId !== null
            && AsignaturaGrupo::query()
                ->whereKey($asignaturaGrupoId)
                ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $request->user()->persona_id))
                ->exists();

        abort_unless($mio || $soyDocente || $request->user()->can('capturar-calificaciones'), 403);

        return Storage::disk('local')->download($archivo->ruta, $archivo->nombre);
    }

    /** La inscripción de quien entró en la materia de esa actividad. */
    private function miInscripcionEn(Request $request, Actividad $actividad): ?Inscripcion
    {
        $asignaturaGrupoId = $actividad->curso?->asignatura_grupo_id;

        if ($asignaturaGrupoId === null) {
            return null;
        }

        return Inscripcion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupoId)
            ->whereIn('matricula_oferta_id', $this->misMatriculas($request)->pluck('id'))
            ->first();
    }
}
