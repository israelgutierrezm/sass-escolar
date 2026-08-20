<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Grabacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Abrir una grabación archivada.
 *
 * ── Por qué esto existe y no un enlace directo ─────────────────────────────
 * Cuando el destino es el disco de la escuela, el archivo no tiene URL pública —y
 * es lo mejor que tiene ese destino—. Aquí se comprueba QUIÉN pide antes de
 * servirlo: el docente de la materia, o un alumno inscrito en ese grupo. Un
 * enlace de Drive o de Dropbox, en cambio, lo abre cualquiera que lo tenga; por
 * eso el destino propio es el único que puede acotar de verdad.
 *
 * ── Dos condiciones, no una ────────────────────────────────────────────────
 * Al alumno se le sirve sólo si la grabación está archivada Y la escuela la
 * marcó visible. Lo segundo no es un detalle: una clase grabada trae caras y
 * voces de menores, y publicarla es una decisión que alguien toma, no un efecto
 * de haber encendido el archivado.
 */
class GrabacionController extends Controller
{
    use AlcanceDelAlumno;
    use AutorizaMateriaPropia;

    public function ver(Request $request, Grabacion $grabacion)
    {
        $clase = $grabacion->clase;

        abort_if($clase === null, 404);

        $esDocente = $this->esDocenteDe($request, $clase->asignatura_grupo_id);

        if (! $esDocente) {
            // Alumno: tiene que estar inscrito en ese grupo Y estar encendida.
            $inscrito = Inscripcion::query()
                ->where('asignatura_grupo_id', $clase->asignatura_grupo_id)
                ->whereIn('matricula_oferta_id', $this->misMatriculas($request)->pluck('id'))
                ->exists();

            // 404 y no 403: si no es suya, para quien pregunta no existe.
            abort_unless($inscrito && $grabacion->laVeElAlumno(), 404);
        }

        abort_unless($grabacion->estaArchivada(), 404);

        /*
         * En un destino externo se REDIRIGE a su enlace: el archivo no está
         * aquí, y proxearlo obligaría al servidor de la escuela a mover cada
         * gigabyte que alguien mire. Lo que Acadion aporta en ese caso es
         * comprobar quién pide antes de entregar la dirección.
         */
        if (filled($grabacion->url_destino)) {
            return redirect()->away($grabacion->url_destino);
        }

        abort_unless(
            filled($grabacion->ruta_destino) && Storage::disk('local')->exists($grabacion->ruta_destino),
            404,
        );

        // `response()->file` y no `download`: un video se ve en el navegador, y
        // forzar la descarga obligaría a bajar 600 MB para mirar dos minutos.
        return Storage::disk('local')->response($grabacion->ruta_destino, $grabacion->nombre);
    }

    /** Enciende o apaga que la vean los alumnos. Sólo el docente de la materia. */
    public function visibilidad(Request $request, Grabacion $grabacion)
    {
        $clase = $grabacion->clase;

        abort_if($clase === null, 404);

        $this->autorizarMateriaPropia($request, $clase->materia);

        $visible = $request->boolean('visible_alumnos');

        $grabacion->update(['visible_alumnos' => $visible]);

        return back(303)->with(
            'exito',
            $visible
                ? 'La grabación ya la pueden ver tus alumnos.'
                : 'La grabación quedó oculta para tus alumnos.',
        );
    }

    private function esDocenteDe(Request $request, int $asignaturaGrupoId): bool
    {
        $personaId = $request->user()?->persona_id;

        if ($personaId === null) {
            return false;
        }

        return AsignaturaGrupo::query()
            ->whereKey($asignaturaGrupoId)
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->exists();
    }
}
