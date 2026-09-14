<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\ControlEscolar\SesionTutoria;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Services\ControlEscolar\SeguimientoDeTutorados;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El portal del TUTOR EDUCATIVO para la app: sus tutorados, cómo va cada uno y la
 * bitácora de lo que se ha hablado.
 *
 * ── El alcance sale del VÍNCULO, no de la URL ──────────────────────────────
 * `api.faceta:tutor_educativo` fija el rol activo; a quiénes acompaña lo decide
 * `SeguimientoDeTutorados` contra la persona autenticada. Un alumno que no es su
 * tutorado → 403, aunque cambie el id.
 *
 * ── Una sola verdad ─────────────────────────────────────────────────────────
 * Todo sale del mismo servicio que la web (`TutoriaController` delega en él): el
 * panorama, la ficha, anotar una sesión y corregir la marca de confidencial. Ve
 * lo académico, no lo financiero.
 */
class TutorApiController extends Controller
{
    public function __construct(private readonly SeguimientoDeTutorados $seguimiento) {}

    /** Mis tutorados, ordenados por quién necesita atención, con el resumen. */
    public function misTutorados(Request $peticion): JsonResponse
    {
        return response()->json($this->seguimiento->panorama($this->personaId($peticion)));
    }

    /** La ficha de un tutorado: cómo va y la bitácora (registra la consulta). */
    public function tutorado(Request $peticion, Persona $alumno): JsonResponse
    {
        $tutoria = $this->seguimiento->exigirTutoriaCon($this->personaId($peticion), $alumno);
        $this->seguimiento->registrarConsulta($alumno, $this->personaId($peticion), $peticion->ip());

        return response()->json($this->seguimiento->ficha($tutoria, $alumno));
    }

    /** Anota una sesión en la bitácora. */
    public function registrarSesion(Request $peticion, Persona $alumno): JsonResponse
    {
        $tutoria = $this->seguimiento->exigirTutoriaCon($this->personaId($peticion), $alumno);

        $datos = $peticion->validate([
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

        return response()->json(['ok' => true]);
    }

    /** Corrige SÓLO la marca de confidencial de una sesión (su autor, su tutoría). */
    public function marcarConfidencial(Request $peticion, Persona $alumno, SesionTutoria $sesion): JsonResponse
    {
        $tutoria = $this->seguimiento->exigirTutoriaCon($this->personaId($peticion), $alumno);

        $this->seguimiento->marcarConfidencial($tutoria, $sesion, $peticion->boolean('confidencial'));

        return response()->json(['ok' => true]);
    }

    /** La persona del tutor. Sin ella no hay a quién acotar. */
    private function personaId(Request $peticion): int
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        return (int) ($usuario->persona_id
            ?? AvisoParaElUsuario::lanzar(403, 'Tu cuenta no está ligada a una persona.'));
    }
}
