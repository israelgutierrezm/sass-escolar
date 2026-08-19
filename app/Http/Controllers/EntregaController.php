<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Entrega;
use App\Models\Lms\EntregaArchivo;
use App\Services\Lms\CalificadorPorRubrica;
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

    public function guardar(Request $request, Actividad $actividad): RedirectResponse
    {
        $inscripcion = $this->miInscripcionEn($request, $actividad);

        AvisoParaElUsuario::si($inscripcion === null, 403, 'Esa actividad no es de una materia que curses.');

        if (! $actividad->tipo->seEntrega()) {
            return back()->with('error', 'Esta actividad es de lectura: no hay nada que entregar.');
        }

        if (! $actividad->abierta()) {
            return back()->with('error', 'La entrega de esta actividad está cerrada.');
        }

        /*
         * Hay trabajos de una sola oportunidad y así lo configuró el docente.
         *
         * Se comprueba AQUÍ y no sólo escondiendo el botón: la pantalla se puede
         * saltar, y lo que está en juego es que alguien reemplace su trabajo
         * después de leer la retroalimentación del docente —o después de ver la
         * calificación de un compañero—.
         */
        $yaEntregada = Entrega::query()
            ->where('actividad_id', $actividad->id)
            ->where('inscripcion_id', $inscripcion->id)
            ->whereNotNull('entregada_en')
            ->exists();

        if ($yaEntregada && ! $actividad->permite_reentrega) {
            return back()->with('error', 'Esta actividad admite una sola entrega, y la tuya ya está registrada.');
        }

        $datos = $request->validate([
            'contenido' => ['nullable', 'string', 'max:20000'],
            'archivos' => ['nullable', 'array', 'max:5'],
            'archivos.*' => ['file', 'max:20480'],
        ], [], ['contenido' => 'respuesta']);

        if (blank($datos['contenido'] ?? null) && ! $request->hasFile('archivos')) {
            return back()->with('error', 'Escribe una respuesta o adjunta al menos un archivo.');
        }

        // Reentregar REEMPLAZA: hay un renglón por alumno y actividad, y crear
        // otro chocaría contra el unique. Lo aprendimos en `inscripcion`.
        $entrega = Entrega::actualizarOReviver(
            ['actividad_id' => $actividad->id, 'inscripcion_id' => $inscripcion->id],
            [
                'contenido' => $datos['contenido'] ?? null,
                'estado' => Entrega::ENTREGADA,
                'entregada_en' => now(),
                // Se decide AHORA y se guarda: si la fecha de cierre se mueve
                // después, el dato de que llegó tarde se habría perdido.
                'tarde' => $actividad->cierra_en !== null && now()->gt($actividad->cierra_en),
                // Reentregar invalida la calificación anterior: se califica lo
                // que hay, no lo que hubo.
                'calificacion' => null,
                'retroalimentacion' => null,
                'calificada_por' => null,
                'calificada_en' => null,
            ],
        );

        /*
         * Y el desglose de la rúbrica, si lo había.
         *
         * La calificación se limpia arriba; el desglose es la explicación de esa
         * calificación y explicaba un trabajo que ya no está. Dejarlo haría que
         * el alumno leyera «Ortografía: insuficiente» sobre el texto corregido
         * que acaba de subir.
         */
        app(CalificadorPorRubrica::class)->olvidar($entrega);

        foreach ($request->file('archivos', []) as $archivo) {
            // Disco `local` (privado), como el resto de los adjuntos del sistema.
            $ruta = $archivo->store("entregas/{$entrega->id}", 'local');

            EntregaArchivo::create([
                'entrega_id' => $entrega->id,
                'ruta' => $ruta,
                'nombre' => $archivo->getClientOriginalName(),
                'bytes' => $archivo->getSize(),
                'mime' => $archivo->getMimeType(),
            ]);
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
