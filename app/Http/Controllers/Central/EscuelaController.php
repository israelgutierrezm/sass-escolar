<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Sexo;
use App\Models\Tenant;
use App\Services\AprovisionadorAcceso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administración de ESCUELAS (tenants) desde la casa.
 *
 * Crear un tenant dispara —síncrono— la provisión: se crea su base de datos, se
 * migra y se siembra con los catálogos base (ver TenancyServiceProvider). Por
 * eso el alta puede tardar unos segundos. Borrar un tenant elimina su BD.
 *
 * El «nombre» y el flag «suspendida» viajan en la columna `data` del tenant
 * (columnas virtuales de stancl): la casa no necesita más que eso.
 */
class EscuelaController extends Controller
{
    /** Dominio base al que se le antepone la clave: clave.localhost. */
    private const DOMINIO_BASE = 'localhost';

    public function index(): View
    {
        $escuelas = Tenant::with('domains')->orderByDesc('created_at')->get()
            ->map(fn (Tenant $t) => $this->resumen($t))
            ->all();

        return view('central.escuelas.index', [
            'escuelas' => $escuelas,
            'dominioBase' => self::DOMINIO_BASE,
            // El catálogo de sexos es landlord (H/M): la persona del
            // administrador exige `sexo_id` (NOT NULL).
            'sexos' => Sexo::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'clave' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/', Rule::unique('tenants', 'id')],
            // Administrador inicial de la escuela: sin él, la escuela nace sin
            // nadie que pueda entrar. Recibe el rol `director_general`, que
            // concentra todos los permisos de la faceta administrativa.
            'admin_nombre' => ['required', 'string', 'max:100'],
            'admin_primer_apellido' => ['required', 'string', 'max:100'],
            'admin_segundo_apellido' => ['nullable', 'string', 'max:100'],
            'admin_sexo_id' => ['required', Rule::exists('sexos', 'id')],
            'admin_email' => ['required', 'email', 'max:150'],
            // `confirmed` exige `admin_password_confirmation` para no fijar una
            // contraseña con un dedazo.
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'clave.regex' => 'La clave va en minúsculas: letras, números y guiones, empezando con letra.',
            'clave.unique' => 'Ya existe una escuela con esa clave.',
            'admin_sexo_id.exists' => 'Selecciona el sexo del administrador.',
            'admin_password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ]);

        $dominio = $datos['clave'].'.'.self::DOMINIO_BASE;

        // Crear el tenant provisiona su BD (síncrono). Luego se le cuelga el
        // dominio por el que la escuela entrará.
        $tenant = Tenant::create(['id' => $datos['clave'], 'nombre' => $datos['nombre']]);
        $tenant->domains()->create(['domain' => $dominio]);

        // Y su administrador, ya dentro de la BD recién sembrada.
        $this->crearAdministrador($tenant, $datos);

        return redirect('/escuelas/'.$tenant->id)
            ->with('exito', "Escuela «{$datos['nombre']}» creada con su administrador. Ya puede entrar en {$dominio}.");
    }

    /**
     * Crea al administrador de la escuela DENTRO del contexto del tenant recién
     * provisionado: su persona, el rol `director_general` (todos los permisos
     * administrativos) y la cuenta con acceso ya configurado.
     *
     * @param  array<string, mixed>  $datos
     */
    private function crearAdministrador(Tenant $tenant, array $datos): void
    {
        tenancy()->initialize($tenant);

        try {
            $rol = Rol::query()->where('name', 'director_general')->first();

            $persona = Persona::create([
                'nombre' => $datos['admin_nombre'],
                'primer_apellido' => $datos['admin_primer_apellido'],
                'segundo_apellido' => $datos['admin_segundo_apellido'] ?? null,
                'sexo_id' => (int) $datos['admin_sexo_id'],
                'email' => $datos['admin_email'],
            ]);

            if ($rol !== null) {
                PersonaRol::create([
                    'persona_id' => $persona->id,
                    'rol_id' => $rol->id,
                    'activo' => true,
                ]);
            }

            Usuario::create([
                'persona_id' => $persona->id,
                'usuario' => app(AprovisionadorAcceso::class)->usuarioDisponible($persona),
                'email' => $datos['admin_email'],
                // El cast `hashed` del modelo la cifra al guardar; nunca se
                // registra ni se expone en claro.
                'password' => $datos['admin_password'],
                'acceso_configurado' => true,
                'rol_activo_id' => $rol?->id,
            ]);
        } finally {
            // Pase lo que pase, se cierra el contexto para no contaminar el
            // resto del request (que corre en el dominio central).
            tenancy()->end();
        }
    }

    public function show(string $id): View
    {
        $tenant = Tenant::with('domains')->findOrFail($id);

        return view('central.escuelas.detalle', [
            'escuela' => [
                ...$this->resumen($tenant),
                'dominios' => $tenant->domains->pluck('domain')->implode(', '),
                'bd' => config('tenancy.database.prefix', 'tenant').$tenant->id,
            ],
        ]);
    }

    /** Alterna el bloqueo de acceso de la escuela (sin borrar nada). */
    public function suspender(string $id): RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);
        $suspendida = ! (bool) ($tenant->suspendida ?? false);

        $tenant->suspendida = $suspendida;
        $tenant->save();

        return back()->with('exito', $suspendida
            ? 'Escuela suspendida: no podrá entrar hasta reactivarla.'
            : 'Escuela reactivada: el acceso quedó restaurado.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);

        // Confirmación tecleada: borrar una escuela elimina su BD entera.
        if ($request->input('confirmacion') !== $tenant->id) {
            return back()->withErrors(['confirmacion' => 'La confirmación no coincide con la clave.']);
        }

        $nombre = $tenant->nombre ?? $tenant->id;
        $tenant->delete(); // dispara DeleteDatabase (síncrono)

        return redirect('/escuelas')
            ->with('exito', "Escuela «{$nombre}» eliminada junto con su base de datos.");
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'nombre' => $tenant->nombre ?? $tenant->id,
            'dominio' => $tenant->domains->first()?->domain,
            'suspendida' => (bool) ($tenant->suspendida ?? false),
            'creada' => $tenant->created_at?->format('d/m/Y H:i'),
        ];
    }
}
