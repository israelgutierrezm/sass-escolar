<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\CredencialesAcceso;
use App\Models\Academico\Campus;
use App\Models\Identidad\BitacoraAcceso;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Rules\CurpValida;
use App\Services\AprovisionadorAcceso;
use App\Services\BitacoraAccesos;
use App\Services\IdentidadPersona;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Usuario $u) => [
                'id' => $u->id,
                'usuario' => $u->usuario,
                'email' => $u->email,
                'persona' => $u->persona?->nombreCompleto(),
                'persona_id' => $u->persona_id,
                'foto' => $u->persona?->urlFoto(),
                'rol_activo' => $u->rolActivo?->nombre,
                // Cuenta de censo: existe y se lista, pero aún no tiene una
                // contraseña usable (se habilita en la etapa de acceso).
                'acceso_configurado' => (bool) $u->acceso_configurado,
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
            // Catálogos del bloque de identidad compartido (géneros, entidades,
            // países, id de México). El alta de la cuenta captura la persona
            // igual que aspirantes: CURP con autollenado, correo como usuario.
            ...app(IdentidadPersona::class)->catalogosDeOrigen(),
        ]);
    }

    /**
     * Ficha de una cuenta: aquí se administran sus roles y su contraseña, en su
     * propia página (antes era un panel que se desplegaba en la fila).
     */
    public function show(Request $request, Usuario $usuario): Response
    {
        $usuario->load(['persona:id,nombre,primer_apellido,segundo_apellido,curp,foto_url', 'rolActivo:id,name,nombre']);

        return Inertia::render('Plataforma/UsuarioDetalle', [
            'usuario' => [
                'id' => $usuario->id,
                'usuario' => $usuario->usuario,
                'email' => $usuario->email,
                'persona' => $usuario->persona?->nombreCompleto(),
                'foto' => $usuario->persona?->urlFoto(),
                'rol_activo' => $usuario->rolActivo?->nombre,
                'acceso_configurado' => (bool) $usuario->acceso_configurado,
                'soy_yo' => $usuario->id === $request->user()->id,
                'roles' => PersonaRol::query()
                    ->with('rol:id,nombre', 'campus:id,nombre')
                    ->where('persona_id', $usuario->persona_id)
                    ->get()
                    ->map(fn (PersonaRol $a) => [
                        'id' => $a->id,
                        'nombre' => $a->rol?->nombre,
                        'campus' => $a->campus?->nombre,
                        'activo' => (bool) $a->activo,
                        // Marca el rol con el que la cuenta opera: la UI no deja
                        // retirarlo (el backend también lo rechaza).
                        'es_activo' => $a->rol_id === $usuario->rol_activo_id,
                    ])->values(),
            ],
            'roles' => Rol::query()
                ->orderByRaw('rol_padre_id is not null')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Rol $r) => [
                    'id' => $r->id,
                    'nombre' => $r->nombre,
                    'faceta' => $r->faceta()->nombre,
                ]),
            'campus' => Campus::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Crea la cuenta. Si la CURP ya existe se reutiliza esa persona: quien
     * entra como docente pudo haber sido alumno, y duplicarlo rompería su
     * kárdex, sus roles y su expediente.
     */
    public function store(Request $request, IdentidadPersona $identidad): RedirectResponse
    {
        // Se captura la PERSONA con el mismo bloque de identidad que aspirantes:
        // la CURP se lee (autollena fecha, género, entidad, país) y el correo es
        // la credencial —no hay un «usuario» aparte—. Ambos, CURP y correo, son
        // únicos por escuela: no puede haber dos personas iguales en un tenant.
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            // Sin `size:18`: hay que dejar pasar EXTRANJERO; `CurpValida`
            // comprueba el dígito verificador. La unicidad de la CURP la da la
            // reutilización por CURP (abajo), no un `unique` que rechazaría a un
            // egresado que vuelve como personal.
            'curp' => array_filter(['nullable', 'string', 'max:20', new CurpValida]),
            'rfc' => ['nullable', 'string', 'max:13'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'genero_id' => ['nullable', 'integer'],
            'entidad_nacimiento_id' => ['nullable', 'integer'],
            'pais_nacimiento_id' => ['nullable', 'integer'],
            // Correo obligatorio (es el usuario del login) y único en la
            // plataforma; se excluye a la persona que se reutiliza por CURP.
            'email' => ['required', 'email', 'max:150', function (string $atributo, mixed $valor, \Closure $fallar) use ($identidad, $request) {
                $excluir = $identidad->existentePorCurp($request->input('curp'))?->id;
                $conflicto = $identidad->correoEnUso($valor, $excluir);

                if ($conflicto !== null) {
                    $fallar('Ese correo ya está registrado con otra persona ('.$conflicto->nombreCompleto().'). Usa otro, o captura su CURP para reutilizarla.');
                }
            }],
            'correo_institucional' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],
            'telefono_local' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'rol_id' => ['required', Rule::exists('roles', 'id')],
            'campus_id' => ['nullable', Rule::exists('campus', 'id')],
            'enviar_credenciales' => ['boolean'],
        ]);

        $datos['curp'] = filled($datos['curp'] ?? null) ? mb_strtoupper(trim($datos['curp'])) : null;
        $datos['email'] = mb_strtolower(trim($datos['email']));

        DB::transaction(function () use ($datos, $identidad) {
            // La CURP manda: si ya existe esa persona se reutiliza (no se
            // duplica su expediente) y se le completan los datos.
            $persona = $identidad->existentePorCurp($datos['curp']);
            $persona === null
                ? $persona = Persona::create($identidad->resolver($datos))
                : $persona->update($identidad->resolver($datos));

            PersonaRol::firstOrCreate([
                'persona_id' => $persona->id,
                'rol_id' => $datos['rol_id'],
                'campus_id' => $datos['campus_id'] ?? null,
            ], ['activo' => true]);

            // firstOrNew y no updateOrCreate: si la persona ya tenía cuenta de
            // censo (es docente, alumno…), se le CONFIGURA el acceso sin pisarle
            // el nombre de usuario que ya tenía. El `usuario` es un identificador
            // técnico —el login es por correo— derivado del correo la primera vez.
            $usuario = Usuario::firstOrNew(['persona_id' => $persona->id]);

            if (! $usuario->exists) {
                $usuario->usuario = app(AprovisionadorAcceso::class)->usuarioDisponible($persona);
            }

            $usuario->fill([
                'email' => $datos['email'],
                'password' => Hash::make($datos['password']),
                'acceso_configurado' => true,
                'rol_activo_id' => $datos['rol_id'],
            ])->save();

            if ($datos['enviar_credenciales'] ?? false) {
                $this->enviarCredenciales($usuario, $datos['password']);
            }
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
        $datos = $request->validate([
            // `confirmed` exige que llegue `password_confirmation` igual: la ficha
            // pide capturarla dos veces para no fijar una contraseña con un dedazo.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'enviar_credenciales' => ['boolean'],
        ]);

        // Ponerle contraseña ES habilitarle el acceso: si era una cuenta de
        // censo, deja de estar «sin acceso».
        $usuario->update([
            'password' => Hash::make($datos['password']),
            'acceso_configurado' => true,
        ]);

        if ($datos['enviar_credenciales'] ?? false) {
            $this->enviarCredenciales($usuario, $datos['password']);

            return back()->with('exito', 'Contraseña restablecida y enviada por correo.');
        }

        return back()->with('exito', 'Contraseña restablecida. Dísela por un medio seguro y pídele que la cambie.');
    }

    /**
     * Manda por correo las credenciales de la cuenta y lo asienta en la
     * bitácora. Silencioso ante fallas de correo: no debe tumbar el alta ni el
     * restablecimiento. Sin correo en la cuenta, no hay a dónde mandarlas.
     */
    private function enviarCredenciales(Usuario $usuario, string $password): void
    {
        $usuario->loadMissing('persona');
        $correo = $usuario->email;

        if (blank($correo)) {
            return;
        }

        try {
            // Si la escuela configuró su correo (Gmail), sale por ahí.
            app(\App\Services\Correo\CorreoService::class)->aplicar();

            Mail::to($correo)->send(new CredencialesAcceso(
                nombre: $usuario->persona?->nombreCompleto() ?? 'Hola',
                correo: $correo,
                password: $password,
                urlAcceso: route('tenant.login'),
                escuela: tenant()?->id,
            ));

            app(BitacoraAccesos::class)->registrar(
                BitacoraAcceso::CREDENCIALES_ENVIADAS,
                request(),
                $usuario,
                $usuario->persona_id,
                ['email' => $correo],
            );
        } catch (\Throwable) {
            // El correo es un extra: su falla no rompe el alta ni el reset.
        }
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
