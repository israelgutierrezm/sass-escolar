<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tablero de la escuela.
 *
 * Por ahora muestra el contexto de sesión —quién eres, con qué rol estás
 * operando, qué te permite ese rol y a qué campus te acota— que es justo lo
 * que valida que el sistema de identidad y permisos funciona de punta a punta.
 */
class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $rolActivo = $usuario->rolActivo;

        return Inertia::render('Dashboard', [
            'jerarquiaRol' => $rolActivo === null ? null : [
                'faceta' => $this->faceta($rolActivo),
                'heredados' => $rolActivo->padre?->permisosEfectivos()->pluck('name')->sort()->values()->all() ?? [],
                'propios' => $rolActivo->permissions->pluck('name')->sort()->values()->all(),
            ],
            'campusDelRol' => $usuario->campusDelRolActivo(),
        ]);
    }

    /**
     * Nombre de la faceta a la que pertenece el rol activo: el ancestro más
     * alto de su cadena (o él mismo, si ya es una faceta).
     */
    private function faceta(\App\Models\Identidad\Rol $rol): string
    {
        $ancestros = $rol->ancestros();

        return $ancestros === [] ? $rol->nombre : end($ancestros)->nombre;
    }
}
