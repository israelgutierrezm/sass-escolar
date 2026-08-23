<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoActividad;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AlcanceDelAlumno;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Entrega;
use App\Models\Lms\PortafolioArchivo;
use App\Models\Lms\PortafolioEvidencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * El portafolio de evidencias del alumno: agregar, describir y ordenar piezas.
 *
 * ── Se apoya en la ENTREGA, no la sustituye ────────────────────────────────
 * La entrega sigue siendo el trabajo —lleva la calificación, la
 * retroalimentación y la rúbrica—; el portafolio son sus PIEZAS. Por eso aquí no
 * se califica nada: el docente lo hace desde el panel de siempre, que ya sabe.
 *
 * ── La entrega nace en BORRADOR y se cierra aparte ─────────────────────────
 * Es la diferencia con una tarea normal. Un portafolio se arma a lo largo del
 * curso: cada evidencia que se agrega NO es una entrega, y darla por entregada
 * al subir la primera dejaría al docente calificando un trabajo a medias. Se
 * entrega cuando el alumno lo dice, con `entregar()`.
 */
class PortafolioController extends Controller
{
    use AlcanceDelAlumno;

    /** Agrega una pieza al portafolio. */
    public function agregar(Request $request, Actividad $actividad): RedirectResponse
    {
        $entrega = $this->miEntregaEn($request, $actividad);

        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'fecha_evidencia' => ['nullable', 'date'],
            'archivos' => ['nullable', 'array', 'max:5'],
            'archivos.*' => ['file', 'max:20480'],
        ], [], [
            'titulo' => 'título',
            'fecha_evidencia' => 'fecha',
        ]);

        /*
         * Una evidencia SIN archivos es legítima —una reflexión escrita lo es—,
         * pero una sin archivos y sin descripción no documenta nada: sería un
         * título suelto en una lista. Se pide una de las dos cosas.
         */
        if (blank($datos['descripcion'] ?? null) && ! $request->hasFile('archivos')) {
            return back(303)->with(
                'error',
                'Describe la evidencia o adjunta al menos un archivo: un título solo no documenta nada.',
            );
        }

        DB::connection('tenant')->transaction(function () use ($request, $datos, $entrega) {
            $evidencia = PortafolioEvidencia::create([
                'entrega_id' => $entrega->id,
                'titulo' => $datos['titulo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'fecha_evidencia' => $datos['fecha_evidencia'] ?? null,
                // Al final de lo que ya hay. El alumno la reacomoda después si
                // quiere: llegar en orden de captura es lo que espera cualquiera.
                'orden' => (int) PortafolioEvidencia::query()
                    ->where('entrega_id', $entrega->id)->max('orden') + 1,
            ]);

            foreach ($request->file('archivos', []) as $archivo) {
                // Disco privado, como todo lo que sube un alumno: son trabajos
                // escolares con su nombre encima.
                PortafolioArchivo::create([
                    'evidencia_id' => $evidencia->id,
                    'ruta' => $archivo->store("portafolios/{$entrega->id}", 'local'),
                    'nombre' => $archivo->getClientOriginalName(),
                    'bytes' => $archivo->getSize(),
                    'mime' => $archivo->getMimeType(),
                ]);
            }
        });

        return back(303)->with('exito', 'Evidencia agregada a tu portafolio.');
    }

    /** Corrige el título, la descripción o la fecha de una pieza. */
    public function editar(Request $request, PortafolioEvidencia $evidencia): RedirectResponse
    {
        $this->exigirQueSeaMiaYEditable($request, $evidencia);

        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'fecha_evidencia' => ['nullable', 'date'],
        ], [], ['titulo' => 'título', 'fecha_evidencia' => 'fecha']);

        $evidencia->update($datos);

        return back(303)->with('exito', 'Evidencia actualizada.');
    }

    /** Retira una pieza. Con borrado lógico: ver el modelo. */
    public function quitar(Request $request, PortafolioEvidencia $evidencia): RedirectResponse
    {
        $this->exigirQueSeaMiaYEditable($request, $evidencia);

        $evidencia->delete();

        return back(303)->with('exito', 'Evidencia retirada.');
    }

    /**
     * Reacomoda las piezas.
     *
     * El orden es una decisión del alumno: es cómo cuenta lo que aprendió. Se
     * reciben los ids en el orden nuevo y se reescriben en bloque —mandar «la
     * 7 va a la posición 3» obligaría a recalcular las demás en el cliente, que
     * es donde se desincroniza—.
     */
    public function ordenar(Request $request, Actividad $actividad): RedirectResponse
    {
        $entrega = $this->miEntregaEn($request, $actividad);

        $datos = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        DB::connection('tenant')->transaction(function () use ($datos, $entrega) {
            foreach ($datos['ids'] as $posicion => $id) {
                /*
                 * Acotado a ESTA entrega: sin eso, mandar el id de la evidencia
                 * de otra persona le reordenaría su portafolio. La lista de ids
                 * viene del navegador y no es una fuente de verdad.
                 */
                PortafolioEvidencia::query()
                    ->where('entrega_id', $entrega->id)
                    ->whereKey($id)
                    ->update(['orden' => $posicion]);
            }
        });

        return back(303)->with('exito', 'Portafolio reordenado.');
    }

    /**
     * Da el portafolio por entregado.
     *
     * Es el gesto que lo pone en la cola del docente. Hasta aquí era un borrador
     * que el alumno iba llenando.
     */
    public function entregar(Request $request, Actividad $actividad): RedirectResponse
    {
        $entrega = $this->miEntregaEn($request, $actividad);

        $piezas = PortafolioEvidencia::query()->where('entrega_id', $entrega->id)->count();

        if ($piezas === 0) {
            return back(303)->with('error', 'Tu portafolio está vacío: agrega al menos una evidencia.');
        }

        $entrega->update([
            'estado' => Entrega::ENTREGADA,
            'entregada_en' => now(),
            // Igual que en una tarea: se decide AHORA y se guarda, porque si la
            // fecha de cierre se mueve después el dato se habría perdido.
            'tarde' => $actividad->cierra_en !== null && now()->gt($actividad->cierra_en),
        ]);

        return back(303)->with(
            'exito',
            $entrega->tarde
                ? 'Portafolio entregado, marcado como fuera de tiempo.'
                : 'Portafolio entregado.',
        );
    }

    /** Descarga de un archivo del portafolio: propio, o del docente. */
    public function archivo(Request $request, PortafolioArchivo $archivo)
    {
        $evidencia = $archivo->evidencia()->with('entrega.actividad.curso')->firstOrFail();
        $entrega = $evidencia->entrega;
        $asignaturaGrupoId = $entrega?->actividad?->curso?->asignatura_grupo_id;

        $mio = $entrega !== null && Inscripcion::query()
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

    /**
     * La entrega-contenedor de esta persona en esta actividad, creándola si es
     * la primera evidencia.
     *
     * Nace en PENDIENTE y con `entregada_en` en null: agregar una pieza no es
     * entregar. Ver la nota de la clase.
     */
    private function miEntregaEn(Request $request, Actividad $actividad): Entrega
    {
        AvisoParaElUsuario::si(
            $actividad->tipo !== TipoActividad::Portafolio,
            403,
            'Esa actividad no es un portafolio de evidencias.',
        );

        $asignaturaGrupoId = $actividad->curso?->asignatura_grupo_id;

        $inscripcion = $asignaturaGrupoId === null ? null : Inscripcion::query()
            ->where('asignatura_grupo_id', $asignaturaGrupoId)
            ->whereIn('matricula_oferta_id', $this->misMatriculas($request)->pluck('id'))
            ->first();

        AvisoParaElUsuario::si($inscripcion === null, 403, 'Esa actividad no es de una materia que curses.');

        AvisoParaElUsuario::si(! $actividad->abierta(), 403, 'Este portafolio ya está cerrado.');

        /*
         * `primeraOReviver` y NO `actualizarOReviver`: el estado sólo se pone
         * al crearla. Con el otro, agregar una pieza a un portafolio ya
         * entregado lo devolvería a PENDIENTE y lo sacaría de la cola del
         * docente sin que nadie lo pidiera.
         */
        $entrega = Entrega::primeraOReviver(
            ['actividad_id' => $actividad->id, 'inscripcion_id' => $inscripcion->id],
            ['estado' => Entrega::PENDIENTE],
        );

        /*
         * Y calificado no se toca, por lo mismo que una evidencia suelta: sumar
         * una pieza después del número dejaría la calificación explicando un
         * trabajo distinto del que hay.
         */
        AvisoParaElUsuario::si(
            $entrega->estaCalificada(),
            403,
            'Este portafolio ya está calificado: para cambiarlo, pídele a tu docente que lo reabra.',
        );

        return $entrega;
    }

    /**
     * Que la evidencia sea suya y todavía se pueda tocar.
     *
     * **Calificada no se toca.** Retirar o reescribir una pieza después de que
     * el docente puso el número dejaría la calificación explicando un trabajo
     * que ya no está — es la misma regla del acta asentada y de la rúbrica
     * congelada. Para cambiarla hay que reabrirla, que es cosa del docente.
     */
    private function exigirQueSeaMiaYEditable(Request $request, PortafolioEvidencia $evidencia): void
    {
        $entrega = $evidencia->entrega()->with('actividad')->firstOrFail();

        $mia = Inscripcion::query()
            ->whereKey($entrega->inscripcion_id)
            ->whereIn('matricula_oferta_id', $this->misMatriculas($request)->pluck('id'))
            ->exists();

        // 404 y no 403: la de otro no tiene por qué revelarse que existe.
        abort_unless($mia, 404);

        AvisoParaElUsuario::si(
            $entrega->estaCalificada(),
            403,
            'Este portafolio ya está calificado: sus evidencias no se pueden cambiar.',
        );

        AvisoParaElUsuario::si(
            $entrega->actividad !== null && ! $entrega->actividad->abierta(),
            403,
            'Este portafolio ya está cerrado.',
        );
    }
}
