<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi perfil»: los datos de la propia CUENTA, separados del cambio de rol.
 *
 * El dropdown de perfil mezclaba conmutar de rol con cerrar sesión y no dejaba
 * editar nada de uno mismo. El cliente lo pidió al revés: el cambio de rol a su
 * propio panel lateral, y el perfil para lo que es de la persona —nombre, foto,
 * contraseña—. Aquí no hay id en la URL: siempre se resuelve la cuenta
 * autenticada, así que nadie edita el perfil de otro por esta vía.
 */
class PerfilController extends Controller
{
    public function show(Request $request): Response
    {
        $usuario = $request->user();
        $persona = $usuario->persona;

        return Inertia::render('Perfil/Index', [
            'perfil' => [
                'usuario' => $usuario->usuario,
                'email' => $usuario->email,
                'nombre' => $persona?->nombre,
                'primer_apellido' => $persona?->primer_apellido,
                'segundo_apellido' => $persona?->segundo_apellido,
                'curp' => $persona?->curp,
                'foto' => $persona?->urlFoto(),
                // La foto usa el endpoint de siempre; la ruta se arma con el id
                // de la persona, no del usuario.
                'persona_id' => $usuario->persona_id,
            ],
            // El rol activo, informativo: para recordar con qué identidad se
            // está operando mientras se editan los datos personales.
            'rolActivo' => $usuario->rolActivo?->nombre,
            // «Mis materias»: solo trae algo si la persona es alumno con
            // inscripciones vigentes. Son las materias abiertas en grupos donde
            // está inscrito —las que después tendrán actividades en el LMS—.
            'materias' => $this->misMaterias($usuario->persona_id),
        ]);
    }

    /**
     * Materias activas del alumno: sus inscripciones vigentes (no de baja) a
     * materias abiertas en grupos. Vacío si la persona no es alumno o no tiene
     * inscripciones. Ordenadas por ciclo (más reciente primero) y materia.
     *
     * @return array<int, array<string, mixed>>
     */
    private function misMaterias(?int $personaId): array
    {
        if ($personaId === null) {
            return [];
        }

        $matriculaIds = MatriculaOferta::query()->where('persona_id', $personaId)->pluck('id');

        if ($matriculaIds->isEmpty()) {
            return [];
        }

        return Inscripcion::query()
            ->with([
                'asignaturaGrupo.planMateria.asignatura:id,nombre',
                'asignaturaGrupo.grupo:id,clave,ciclo_id',
                'asignaturaGrupo.grupo.ciclo:id,clave,nombre',
                'asignaturaGrupo.docentes.persona',
                'situacion:id,clave,nombre',
                'tipoEvaluacion:id,nombre',
            ])
            ->whereIn('matricula_oferta_id', $matriculaIds)
            ->whereHas('situacion', fn ($q) => $q->where('clave', '!=', 'baja'))
            ->get()
            ->map(function (Inscripcion $i) {
                $ag = $i->asignaturaGrupo;
                $titular = $ag?->docentes->firstWhere('pivot.tipo', 'titular');

                return [
                    'id' => $i->id,
                    'materia' => $ag?->planMateria?->asignatura?->nombre,
                    'clave_en_plan' => $ag?->planMateria?->clave_en_plan,
                    'grupo' => $ag?->grupo?->clave,
                    'ciclo' => $ag?->grupo?->ciclo?->clave,
                    'ciclo_nombre' => $ag?->grupo?->ciclo?->nombre,
                    'docente' => $titular?->persona?->nombreCompleto(),
                    'tipo_evaluacion' => $i->tipoEvaluacion?->nombre,
                    'situacion' => $i->situacion?->nombre,
                ];
            })
            ->sortByDesc('ciclo')
            ->values()
            ->all();
    }

    /**
     * Nombre y correo. El correo es la credencial de acceso, así que se cuida su
     * unicidad; no se toca la contraseña aquí —tiene su propio formulario, para
     * no exigir reescribirla cada vez que se corrige un apellido—.
     */
    public function actualizar(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:150', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
        ], [], [
            'primer_apellido' => 'primer apellido',
            'segundo_apellido' => 'segundo apellido',
            'email' => 'correo',
        ]);

        $usuario->persona?->update([
            'nombre' => $datos['nombre'],
            'primer_apellido' => $datos['primer_apellido'],
            // Vacío se guarda como null, no como cadena vacía: «sin segundo
            // apellido» es la ausencia del dato, y así se compara y se muestra
            // igual que quien nunca lo tuvo.
            'segundo_apellido' => filled($datos['segundo_apellido'] ?? null) ? $datos['segundo_apellido'] : null,
        ]);

        $usuario->update(['email' => $datos['email']]);

        return back()->with('exito', 'Perfil actualizado.');
    }

    /**
     * Cambio de contraseña propia. Exige la actual: cambiar la contraseña de
     * uno mismo sin conocer la vigente convertiría una sesión olvidada abierta
     * en un secuestro de cuenta.
     */
    public function password(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        $request->validate([
            'actual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'actual.current_password' => 'La contraseña actual no es correcta.',
        ], [
            'actual' => 'contraseña actual',
            'password' => 'nueva contraseña',
        ]);

        $usuario->update(['password' => Hash::make($request->input('password'))]);

        return back()->with('exito', 'Contraseña actualizada.');
    }
}
