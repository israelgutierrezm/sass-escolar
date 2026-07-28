<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'clave' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/', Rule::unique('tenants', 'id')],
        ], [
            'clave.regex' => 'La clave va en minúsculas: letras, números y guiones, empezando con letra.',
            'clave.unique' => 'Ya existe una escuela con esa clave.',
        ]);

        $dominio = $datos['clave'].'.'.self::DOMINIO_BASE;

        // Crear el tenant provisiona su BD (síncrono). Luego se le cuelga el
        // dominio por el que la escuela entrará.
        $tenant = Tenant::create(['id' => $datos['clave'], 'nombre' => $datos['nombre']]);
        $tenant->domains()->create(['domain' => $dominio]);

        return redirect('/escuelas/'.$tenant->id)
            ->with('exito', "Escuela «{$datos['nombre']}» creada. Ya puede entrar en {$dominio}.");
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
