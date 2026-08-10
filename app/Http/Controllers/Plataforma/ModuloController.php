<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qué secciones tiene encendidas la escuela.
 *
 * Apagar aquí no esconde un botón: cierra las rutas del módulo —ver el
 * middleware `ModuloEncendido`—, así que quien tenga la dirección guardada
 * tampoco entra.
 */
class ModuloController extends Controller
{
    public function __construct(private readonly ModulosDeLaEscuela $modulos) {}

    public function index(Request $peticion): Response
    {
        return Inertia::render('Plataforma/Modulos', [
            'modulos' => $this->modulos->catalogo(),
            // Ver la lista es útil para quien coordina —explica por qué a un
            // alumno no le aparece una sección—, pero moverla es otra cosa.
            'puedeEditar' => $peticion->user()?->can('editar-configuracion') ?? false,
        ]);
    }

    public function actualizar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'clave' => ['required', 'string', 'max:50'],
            'activo' => ['required', 'boolean'],
        ]);

        $this->modulos->cambiar($datos['clave'], $datos['activo']);

        return back()->with(
            'success',
            $datos['activo'] ? 'La sección quedó disponible.' : 'La sección quedó cerrada para todos.',
        );
    }
}
