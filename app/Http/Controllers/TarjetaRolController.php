<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Rol;
use App\Models\Identidad\TarjetaRol;
use App\Panel\RegistroTarjetas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editor del panel por rol: enciende/apaga las tarjetas (widgets) que ve cada
 * rol en su panel. Sin configuración, el rol ve todas las que su permiso
 * permite. Encender no otorga permiso: el permiso sigue filtrando primero.
 */
class TarjetaRolController extends Controller
{
    public function __construct(private readonly RegistroTarjetas $registro) {}

    public function index(): Response
    {
        $configs = TarjetaRol::query()->get(['rol_id', 'activas'])->keyBy('rol_id');

        return Inertia::render('Plataforma/Tarjetas', [
            'catalogo' => $this->registro->catalogo(),
            'roles' => Rol::query()->orderBy('nombre')->get()
                ->map(fn (Rol $rol) => [
                    'id' => $rol->id,
                    'nombre' => $rol->nombre,
                    // Con qué permisos cuenta el rol: el editor solo ofrece las
                    // tarjetas que ese rol podría ver.
                    'permisos' => $rol->permisosEfectivos()->pluck('name')->values(),
                    // Lista de encendidas, o null → default (todas las permitidas).
                    'activas' => $configs->get($rol->id)?->activas,
                ]),
        ]);
    }

    public function guardar(Request $request, Rol $rol): RedirectResponse
    {
        $datos = $request->validate([
            'activas' => ['present', 'array'],
            'activas.*' => ['string'],
        ]);

        // Solo se guardan claves que existen en el catálogo.
        $validas = array_column($this->registro->catalogo(), 'clave');
        $activas = array_values(array_unique(array_intersect($datos['activas'], $validas)));

        TarjetaRol::updateOrCreate(['rol_id' => $rol->id], ['activas' => $activas]);

        return back()->with('exito', "Panel de «{$rol->nombre}» guardado.");
    }

    public function restablecer(Rol $rol): RedirectResponse
    {
        // Quitar la fila (hard delete) = volver al default: todas las permitidas.
        TarjetaRol::query()->where('rol_id', $rol->id)->forceDelete();

        return back()->with('exito', "Panel de «{$rol->nombre}» restablecido: muestra todas las tarjetas permitidas.");
    }
}
