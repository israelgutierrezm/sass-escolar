<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bolsa;

use App\Http\Controllers\Controller;
use App\Models\Bolsa\Empresa;
use App\Models\Bolsa\EmpresaContacto;
use App\Models\Bolsa\SectorEconomico;
use App\Models\Bolsa\SituacionEmpresa;
use App\Models\Bolsa\TamanoEmpresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El padrón de empleadores de la bolsa de trabajo.
 *
 * ── La empresa se apaga, no se borra ──────────────────────────────────────
 * No hay acción de eliminar: una empresa con la que la escuela no quiere
 * volver a trabajar se pasa a «vetada», y así deja de poder publicar sin
 * llevarse consigo las colocaciones históricas —que son el insumo de los
 * reportes de acreditación—.
 */
class EmpresaController extends Controller
{
    public function index(Request $peticion): Response
    {
        $filtros = [
            'busqueda' => trim((string) $peticion->query('busqueda', '')),
            'sector_id' => $peticion->query('sector_id'),
            'situacion_id' => $peticion->query('situacion_id'),
        ];

        $empresas = Empresa::query()
            ->with(['sector:id,nombre', 'tamano:id,nombre', 'situacion:id,clave,nombre'])
            ->withCount('contactos')
            ->when($filtros['busqueda'] !== '', function ($q) use ($filtros) {
                $termino = "%{$filtros['busqueda']}%";

                $q->where(fn ($w) => $w->where('razon_social', 'like', $termino)->orWhere('rfc', 'like', $termino));
            })
            ->when($filtros['sector_id'], fn ($q, $v) => $q->where('sector_id', $v))
            ->when($filtros['situacion_id'], fn ($q, $v) => $q->where('situacion_id', $v))
            ->orderBy('razon_social')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Bolsa/Empresas', [
            'empresas' => $empresas->through(fn (Empresa $e) => [
                'id' => $e->id,
                'razon_social' => $e->razon_social,
                'rfc' => $e->rfc,
                'sector' => $e->sector?->nombre,
                'tamano' => $e->tamano?->nombre,
                'situacion' => $e->situacion?->nombre,
                'situacion_clave' => $e->situacion?->clave,
                'sitio_web' => $e->sitio_web,
                'contactos' => $e->contactos_count,
            ]),
            'filtros' => $filtros,
            'catalogos' => [
                'sectores' => SectorEconomico::query()->activos()->get(['id', 'nombre']),
                'tamanos' => TamanoEmpresa::query()->activos()->get(['id', 'nombre']),
                'situaciones' => SituacionEmpresa::query()->activos()->get(['id', 'nombre']),
            ],
        ]);
    }

    public function show(Empresa $empresa): Response
    {
        $empresa->load(['sector:id,nombre', 'tamano:id,nombre', 'situacion:id,clave,nombre', 'contactos']);

        return Inertia::render('Bolsa/Empresa', [
            'empresa' => [
                'id' => $empresa->id,
                'razon_social' => $empresa->razon_social,
                'rfc' => $empresa->rfc,
                'sector_id' => $empresa->sector_id,
                'tamano_id' => $empresa->tamano_id,
                'situacion_id' => $empresa->situacion_id,
                'sitio_web' => $empresa->sitio_web,
                'notas' => $empresa->notas,
                'situacion' => $empresa->situacion?->nombre,
            ],
            'contactos' => $empresa->contactos->map(fn (EmpresaContacto $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'puesto' => $c->puesto,
                'email' => $c->email,
                'telefono' => $c->telefono,
                'es_principal' => $c->es_principal,
            ]),
            'catalogos' => [
                'sectores' => SectorEconomico::query()->activos()->get(['id', 'nombre']),
                'tamanos' => TamanoEmpresa::query()->activos()->get(['id', 'nombre']),
                'situaciones' => SituacionEmpresa::query()->activos()->get(['id', 'nombre']),
            ],
        ]);
    }

    public function guardar(Request $peticion, ?Empresa $empresa = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            /*
             * Único cuando está puesto: una escuela captura empleadores que le
             * llaman antes de tener un papel suyo, así que no se exige — pero
             * la misma empresa capturada tres veces sí es un problema, porque
             * sus colocaciones acabarían repartidas entre los duplicados.
             */
            'rfc' => [
                'nullable', 'string', 'max:13',
                Rule::unique('empresas', 'rfc')->ignore($empresa?->id)->whereNull('deleted_at'),
            ],
            'sector_id' => ['nullable', Rule::exists('sectores_economicos', 'id')],
            'tamano_id' => ['nullable', Rule::exists('tamanos_empresa', 'id')],
            'situacion_id' => ['required', Rule::exists('situaciones_empresa', 'id')],
            'sitio_web' => ['nullable', 'string', 'max:255', 'starts_with:http://,https://'],
            'notas' => ['nullable', 'string', 'max:500'],
        ], [
            'rfc.unique' => 'Ya hay una empresa registrada con ese RFC.',
            'sitio_web.starts_with' => 'El sitio tiene que empezar con http:// o https://.',
        ]);

        $empresa === null || ! $empresa->exists
            ? Empresa::create($datos)
            : $empresa->update($datos);

        return back(303)->with('exito', 'Empresa guardada.');
    }

    public function guardarContacto(Request $peticion, Empresa $empresa): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:200'],
            'puesto' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'es_principal' => ['boolean'],
        ]);

        DB::transaction(function () use ($empresa, $datos) {
            /*
             * Uno solo puede ser el principal.
             *
             * Sin esto, marcar a alguien nuevo deja dos y la pantalla enseña el
             * que salga primero — o sea, al azar. Se degrada al anterior en la
             * misma transacción para que no haya un instante con dos ni con
             * ninguno.
             */
            if ($datos['es_principal'] ?? false) {
                $empresa->contactos()->update(['es_principal' => false]);
            }

            $empresa->contactos()->create($datos);
        });

        return back(303)->with('exito', 'Contacto agregado.');
    }

    public function eliminarContacto(Empresa $empresa, EmpresaContacto $contacto): RedirectResponse
    {
        // Que el contacto sea DE esa empresa: la ruta lleva los dos ids y sin
        // esto se borraría el de cualquier otra.
        abort_unless($contacto->empresa_id === $empresa->id, 404);

        $contacto->delete();

        return back(303)->with('exito', 'Contacto eliminado.');
    }
}
