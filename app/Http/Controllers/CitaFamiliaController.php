<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Models\Identidad\Persona;
use App\Services\Familia\GestorDeCitas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal de la FAMILIA para pedir citas con los docentes de su hijo.
 * Ver `docs/plan-citas-familia-docente.md`.
 *
 * El alcance NO lo da el permiso sino el VÍNCULO: sólo se piden citas por un hijo
 * propio (`tutores_alumno`) y con un docente que le da clase. Lo ajeno responde
 * 404, no 403: un id ajeno no confirma que exista.
 */
class CitaFamiliaController extends Controller
{
    public function __construct(private readonly GestorDeCitas $gestor) {}

    public function index(Request $peticion, Persona $hijo): Response
    {
        $tutorId = (int) $peticion->user()->persona_id;
        AvisoParaElUsuario::aMenosQue($this->gestor->esHijoDe($tutorId, $hijo->id), 404, 'Ese alumno no está vinculado a tu cuenta.');

        $docentes = $this->gestor->docentesDelAlumno($hijo->id);
        $ventanas = $this->gestor->ventanasPorDocente(array_column($docentes, 'persona_id'));

        return Inertia::render('Padre/Citas', [
            'hijo' => ['id' => $hijo->id, 'nombre' => $hijo->nombreCompleto()],
            'docentes' => array_map(fn (array $d) => [...$d, 'ventanas' => $ventanas[$d['persona_id']] ?? []], $docentes),
            'modalidades' => DisponibilidadCitaDocente::MODALIDADES,
            'citas' => Cita::query()
                ->where('alumno_persona_id', $hijo->id)
                ->where('solicitante_persona_id', $tutorId)
                ->with('docente:id,nombre,primer_apellido,segundo_apellido')
                ->orderByDesc('inicio')->limit(100)->get()
                ->map(fn (Cita $c) => [
                    'id' => $c->id,
                    'docente' => $c->docente?->nombreCompleto(),
                    'inicio' => $c->inicio?->format('Y-m-d H:i'),
                    'fin' => $c->fin?->format('H:i'),
                    'modalidad' => $c->modalidad,
                    'motivo' => $c->motivo,
                    'lugar' => $c->lugar,
                    'estado' => $c->estado,
                    'respuesta' => $c->respuesta,
                    'ya_paso' => $c->yaPaso(),
                ])->values(),
        ]);
    }

    public function solicitar(Request $peticion, Persona $hijo): RedirectResponse
    {
        $datos = $peticion->validate([
            'disponibilidad_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        $this->gestor->solicitar(
            (int) $peticion->user()->persona_id,
            $hijo->id,
            (int) $datos['disponibilidad_id'],
            $datos['fecha'],
            $datos['hora_inicio'],
            $datos['motivo'],
        );

        return back(303)->with('exito', 'Solicitud enviada. El docente la confirmará o te propondrá otra hora.');
    }

    public function cancelar(Request $peticion, Cita $cita): RedirectResponse
    {
        $datos = $peticion->validate(['respuesta' => ['required', 'string', 'max:500']]);

        $this->gestor->cancelar($cita, (int) $peticion->user()->persona_id, $datos['respuesta']);

        return back(303)->with('exito', 'Cita cancelada.');
    }
}
