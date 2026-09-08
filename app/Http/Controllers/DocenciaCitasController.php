<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Services\Familia\GestorDeCitas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal del DOCENTE para las citas con familias: sus horarios de atención y las
 * solicitudes de sus alumnos. Ver `docs/plan-citas-familia-docente.md`.
 *
 * El permiso `gestionar-mis-citas` deja entrar; el ALCANCE lo pone que la cita
 * sea suya (`GestorDeCitas::exigirDocente`), no el permiso. La disponibilidad es
 * de ATENCIÓN A PADRES, aparte de la de dar clase.
 */
class DocenciaCitasController extends Controller
{
    public function __construct(private readonly GestorDeCitas $gestor) {}

    public function index(Request $peticion): Response
    {
        $docenteId = (int) $peticion->user()->persona_id;

        $citas = Cita::query()
            ->where('docente_persona_id', $docenteId)
            ->with(['alumno:id,nombre,primer_apellido,segundo_apellido', 'solicitante:id,nombre,primer_apellido,segundo_apellido'])
            ->orderByDesc('inicio')
            ->limit(200)
            ->get()
            ->map(fn (Cita $c) => $this->citaComoArray($c));

        return Inertia::render('Docencia/Citas', [
            'disponibilidad' => $this->gestor->ventanasDe($docenteId)->map(fn (DisponibilidadCitaDocente $d) => [
                'id' => $d->id,
                'dia_semana' => $d->dia_semana,
                'hora_inicio' => substr((string) $d->hora_inicio, 0, 5),
                'hora_fin' => substr((string) $d->hora_fin, 0, 5),
                'modalidad' => $d->modalidad,
                'duracion_min' => $d->duracion_min,
                'lugar' => $d->lugar,
            ])->values(),
            'citas' => $citas->values(),
            'modalidades' => DisponibilidadCitaDocente::MODALIDADES,
        ]);
    }

    public function guardarDisponibilidad(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'dia_semana' => ['required', 'integer', 'between:1,7'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
            'modalidad' => ['required', 'in:'.implode(',', array_keys(DisponibilidadCitaDocente::MODALIDADES))],
            'duracion_min' => ['required', 'integer', 'between:5,240'],
            'lugar' => ['nullable', 'string', 'max:200'],
        ]);

        DisponibilidadCitaDocente::create([...$datos, 'persona_id' => (int) $peticion->user()->persona_id]);

        return back(303)->with('exito', 'Se agregó tu horario de atención.');
    }

    public function eliminarDisponibilidad(Request $peticion, DisponibilidadCitaDocente $disponibilidad): RedirectResponse
    {
        // 404 y no 403: una ventana ajena no debe confirmar que exista.
        abort_unless((int) $disponibilidad->persona_id === (int) $peticion->user()->persona_id, 404);

        $disponibilidad->delete();

        return back(303)->with('exito', 'Se quitó el horario.');
    }

    public function confirmar(Request $peticion, Cita $cita): RedirectResponse
    {
        $datos = $peticion->validate([
            'respuesta' => ['nullable', 'string', 'max:500'],
            'lugar' => ['nullable', 'string', 'max:200'],
        ]);

        $this->gestor->confirmar($cita, (int) $peticion->user()->persona_id, $datos['respuesta'] ?? null, $datos['lugar'] ?? null);

        return back(303)->with('exito', 'Cita confirmada. Se avisó a la familia.');
    }

    public function rechazar(Request $peticion, Cita $cita): RedirectResponse
    {
        $datos = $peticion->validate(['respuesta' => ['required', 'string', 'max:500']], [
            'respuesta.required' => 'El rechazo necesita un motivo: es lo único que la familia puede usar para volver a pedir.',
        ]);

        $this->gestor->rechazar($cita, (int) $peticion->user()->persona_id, $datos['respuesta']);

        return back(303)->with('exito', 'Se avisó a la familia.');
    }

    public function cancelar(Request $peticion, Cita $cita): RedirectResponse
    {
        $datos = $peticion->validate(['respuesta' => ['required', 'string', 'max:500']]);

        $this->gestor->cancelar($cita, (int) $peticion->user()->persona_id, $datos['respuesta']);

        return back(303)->with('exito', 'Cita cancelada. Se avisó a la familia.');
    }

    public function marcar(Request $peticion, Cita $cita): RedirectResponse
    {
        $datos = $peticion->validate(['estado' => ['required', 'in:'.Cita::REALIZADA.','.Cita::NO_ASISTIO]]);

        $this->gestor->marcar($cita, (int) $peticion->user()->persona_id, $datos['estado']);

        return back(303)->with('exito', 'Registrado.');
    }

    /** @return array<string, mixed> */
    private function citaComoArray(Cita $c): array
    {
        return [
            'id' => $c->id,
            'alumno' => $c->alumno?->nombreCompleto(),
            'solicitante' => $c->solicitante?->nombreCompleto(),
            'inicio' => $c->inicio?->format('Y-m-d H:i'),
            'fin' => $c->fin?->format('H:i'),
            'modalidad' => $c->modalidad,
            'motivo' => $c->motivo,
            'lugar' => $c->lugar,
            'estado' => $c->estado,
            'respuesta' => $c->respuesta,
            'ya_paso' => $c->yaPaso(),
        ];
    }
}
