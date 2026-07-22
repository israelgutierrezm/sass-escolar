<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración de cuentas.
 *
 * `gestionar-usuarios` existía en el catálogo desde el slice de auth y **no
 * tenía pantalla**: crear una cuenta obligaba a tocar la base o a correr el
 * comando de demo. Es lo primero que hace falta al poner el sistema en manos de
 * una escuela.
 *
 * La cuenta cuelga de una PERSONA, no la reemplaza: dar de alta un usuario es
 * darle credenciales a alguien que ya existe en el directorio, o crear a esa
 * persona si es nueva. Nunca se duplica —se busca por CURP primero—, que es la
 * misma regla de cero recaptura de todo el sistema.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'q' => trim((string) $request->query('q', '')),
            'rol_id' => $request->query('rol_id'),
            'campus_id' => $request->query('campus_id'),
        ];

        $usuarios = Usuario::query()
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido,curp,foto_url', 'rolActivo:id,name,nombre'])
            // El paréntesis no es adorno: al sumar los filtros de rol y campus,
            // un `or` suelto se llevaría por delante la condición anterior y la
            // pantalla devolvería usuarios que no cumplen ningún filtro.
            ->when($filtros['q'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->whereHas('persona', fn ($p) => $p
                    ->whereRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) like ?", ["%{$filtros['q']}%"])
                    ->orWhere('curp', 'like', "%{$filtros['q']}%"))
                ->orWhere('usuario', 'like', "%{$filtros['q']}%")
                ->orWhere('email', 'like', "%{$filtros['q']}%")))
            // Se filtra por la ASIGNACIÓN, no por el rol activo: alguien que hoy
            // navega como docente sigue siendo encargado de admisiones, y quien
            // busca «todos los de admisiones» espera encontrarlo.
            ->when($filtros['rol_id'], fn ($q, $v) => $q->whereHas(
                'persona.asignacionesRol',
                fn ($r) => $r->where('rol_id', $v),
            ))
            ->when($filtros['campus_id'], fn ($q, $v) => $q->whereHas(
                'persona.asignacionesRol',
                fn ($r) => $r->where('campus_id', $v),
            ))
            ->orderBy('usuario')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Usuario $u) => [
                'id' => $u->id,
                'usuario' => $u->usuario,
                'email' => $u->email,
                'persona' => $u->persona?->nombreCompleto(),
                'persona_id' => $u->persona_id,
                'foto' => $u->persona?->urlFoto(),
                'rol_activo' => $u->rolActivo?->nombre,
                'roles' => PersonaRol::query()
                    ->with('rol:id,nombre', 'campus:id,nombre')
                    ->where('persona_id', $u->persona_id)
                    ->get()
                    ->map(fn (PersonaRol $a) => [
                        'id' => $a->id,
                        'nombre' => $a->rol?->nombre,
                        'campus' => $a->campus?->nombre,
                        'activo' => (bool) $a->activo,
                    ])->values(),
                // Es la cuenta de quien está mirando: la pantalla lo marca para
                // que nadie se retire a sí mismo sin darse cuenta.
                'soy_yo' => $u->id === $request->user()->id,
            ]);

        return Inertia::render('Plataforma/Usuarios', [
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'roles' => Rol::query()
                ->with('padre:id,nombre')
                ->orderByRaw('rol_padre_id is not null')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Rol $r) => [
                    'id' => $r->id,
                    'nombre' => $r->nombre,
                    'faceta' => $r->faceta()->nombre,
                    'es_faceta' => $r->rol_padre_id === null,
                ]),
            'campus' => Campus::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Crea la cuenta. Si la CURP ya existe se reutiliza esa persona: quien
     * entra como docente pudo haber sido alumno, y duplicarlo rompería su
     * kárdex, sus roles y su expediente.
     */
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'primer_apellido' => ['required', 'string', 'max:100'],
            'segundo_apellido' => ['nullable', 'string', 'max:100'],
            'curp' => ['nullable', 'string', 'size:18'],
            'sexo_id' => ['required', 'integer'],
            'usuario' => ['required', 'string', 'max:60', Rule::unique('usuarios', 'usuario')],
            'email' => ['required', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'rol_id' => ['required', Rule::exists('roles', 'id')],
            'campus_id' => ['nullable', Rule::exists('campus', 'id')],
        ]);

        DB::transaction(function () use ($datos) {
            $persona = filled($datos['curp'] ?? null)
                ? Persona::query()->where('curp', strtoupper($datos['curp']))->first()
                : null;

            // Los campos vacíos del alta NO pisan lo que ya estaba: si la
            // persona existe, se le agrega la cuenta y nada más.
            $persona ??= Persona::create([
                'nombre' => $datos['nombre'],
                'primer_apellido' => $datos['primer_apellido'],
                'segundo_apellido' => $datos['segundo_apellido'] ?? null,
                'curp' => filled($datos['curp'] ?? null) ? strtoupper($datos['curp']) : null,
                'sexo_id' => $datos['sexo_id'],
            ]);

            PersonaRol::firstOrCreate([
                'persona_id' => $persona->id,
                'rol_id' => $datos['rol_id'],
                'campus_id' => $datos['campus_id'] ?? null,
            ], ['activo' => true]);

            Usuario::create([
                'persona_id' => $persona->id,
                'usuario' => $datos['usuario'],
                'email' => $datos['email'],
                'password' => Hash::make($datos['password']),
                'rol_activo_id' => $datos['rol_id'],
            ]);
        });

        return back()->with('exito', 'Cuenta creada.');
    }

    /** Le agrega un rol a la persona de esa cuenta. */
    public function asignarRol(Request $request, Usuario $usuario): RedirectResponse
    {
        $datos = $request->validate([
            'rol_id' => ['required', Rule::exists('roles', 'id')],
            'campus_id' => ['nullable', Rule::exists('campus', 'id')],
        ]);

        $repetida = PersonaRol::query()
            ->where('persona_id', $usuario->persona_id)
            ->where('rol_id', $datos['rol_id'])
            ->where(fn ($q) => ($datos['campus_id'] ?? null) === null
                ? $q->whereNull('campus_id')
                : $q->where('campus_id', $datos['campus_id']))
            ->exists();

        if ($repetida) {
            return back()->with('error', 'Esa persona ya tiene ese rol con ese alcance.');
        }

        PersonaRol::create($datos + ['persona_id' => $usuario->persona_id, 'activo' => true]);

        return back()->with('exito', 'Rol asignado.');
    }

    public function retirarRol(Request $request, Usuario $usuario, PersonaRol $asignacion): RedirectResponse
    {
        abort_unless($asignacion->persona_id === $usuario->persona_id, 404);

        // Quitarle a alguien el rol con el que está operando lo deja sin
        // contexto a medio camino. El middleware lo reasignaría, pero es mejor
        // explicarlo que sorprender.
        if ($usuario->rol_activo_id === $asignacion->rol_id) {
            return back()->with('error', 'Es el rol activo de esa cuenta. Cámbiaselo antes de retirárselo.');
        }

        if (PersonaRol::query()->where('persona_id', $usuario->persona_id)->count() === 1) {
            return back()->with('error', 'Es su único rol: se quedaría sin poder entrar. Asígnale otro primero.');
        }

        $asignacion->delete();

        return back()->with('exito', 'Rol retirado.');
    }

    /**
     * Restablece la contraseña. No se muestra la anterior porque no se puede:
     * está hasheada, que es como debe estar.
     */
    public function restablecerPassword(Request $request, Usuario $usuario): RedirectResponse
    {
        $datos = $request->validate(['password' => ['required', 'string', 'min:8']]);

        $usuario->update(['password' => Hash::make($datos['password'])]);

        return back()->with('exito', 'Contraseña restablecida. Dísela por un medio seguro y pídele que la cambie.');
    }

    /**
     * Una cuenta no se borra: se desactiva quitándole sus roles activos, o se
     * deja sin poder entrar. Borrarla dejaría sin autor las actas que firmó y
     * los movimientos que capturó.
     */
    public function destroy(Request $request, Usuario $usuario): RedirectResponse
    {
        if ($usuario->id === $request->user()->id) {
            return back()->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        return back()->with(
            'error',
            'Las cuentas no se eliminan: quedarían sin autor las actas que firmó y lo que capturó. '
            .'Retírale sus roles o restablécele la contraseña.'
        );
    }
}
