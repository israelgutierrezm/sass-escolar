<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\SesionTutoria;
use App\Models\Identidad\Persona;
use App\Services\ControlEscolar\SeguimientoDeTutorados;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * "Mis tutorados": el tutor educativo y los alumnos que acompaña.
 *
 * El alcance lo da el VÍNCULO en `tutorias`, no el permiso: `ver-mis-tutorados`
 * deja entrar; a quiénes ve lo decide `SeguimientoDeTutorados`, que comparte con
 * la app. Ve lo académico, no lo financiero. Aquí sólo se autoriza y se traduce
 * a Inertia.
 */
class TutoriaController extends Controller
{
    public function __construct(private readonly SeguimientoDeTutorados $seguimiento) {}

    public function misTutorados(Request $request): Response
    {
        return Inertia::render('Tutorias/MisTutorados', $this->seguimiento->panorama($this->miPersonaId($request)));
    }

    public function tutorado(Request $request, Persona $alumno): Response
    {
        $tutoria = $this->seguimiento->exigirTutoriaCon($this->miPersonaId($request), $alumno);

        // También se registra cuando la abre su propio tutor: lo que hay que poder
        // reconstruir es quién la VIO, en su sesión abierta.
        $this->seguimiento->registrarConsulta($alumno, (int) $request->user()->persona_id, $request->ip());

        return Inertia::render('Tutorias/Tutorado', $this->seguimiento->ficha($tutoria, $alumno));
    }

    /** Anota una sesión en la bitácora. */
    public function registrarSesion(Request $request, Persona $alumno): RedirectResponse
    {
        $tutoria = $this->seguimiento->exigirTutoriaCon($this->miPersonaId($request), $alumno);

        $datos = $request->validate([
            // No se anotan sesiones a futuro: la bitácora dice lo que PASÓ.
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'modalidad' => ['required', Rule::in(array_keys(SesionTutoria::MODALIDADES))],
            'motivo' => ['required', Rule::in(array_keys(SesionTutoria::MOTIVOS))],
            'tema' => ['required', 'string', 'max:2000'],
            'acuerdos' => ['nullable', 'string', 'max:2000'],
            'asistio' => ['boolean'],
            'confidencial' => ['boolean'],
        ], [
            'fecha.before_or_equal' => 'No puedes anotar una sesión que todavía no ocurre.',
        ], [
            'tema' => 'lo que se habló',
            'acuerdos' => 'los acuerdos',
        ]);

        $this->seguimiento->registrarSesion($tutoria, $datos);

        return back(303)->with('exito', 'Sesión registrada en la bitácora.');
    }

    /**
     * Corrige la marca de confidencial de una sesión ya anotada: sólo la marca
     * (el texto es su testimonio) y sólo su autor —que coordinación pudiera
     * desmarcar vaciaría la figura—.
     */
    public function marcarConfidencial(Request $request, Persona $alumno, SesionTutoria $sesion): RedirectResponse
    {
        $tutoria = $this->seguimiento->exigirTutoriaCon($this->miPersonaId($request), $alumno);

        $confidencial = $request->boolean('confidencial');
        $this->seguimiento->marcarConfidencial($tutoria, $sesion, $confidencial);

        return back(303)->with(
            'exito',
            $confidencial
                ? 'La sesión quedó marcada como confidencial: control escolar verá que ocurrió, pero no lo que anotaste.'
                : 'La sesión dejó de ser confidencial: quien tenga permiso podrá leerla.',
        );
    }

    /** La persona del tutor, o 403 si quien entra no tiene una. */
    private function miPersonaId(Request $request): int
    {
        return (int) ($request->user()?->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.'));
    }
}
