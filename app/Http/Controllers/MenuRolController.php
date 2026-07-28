<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\MenuRol;
use App\Models\Identidad\Rol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editor del menú lateral por rol: la escuela reordena y anida (hasta 3 niveles)
 * los grupos y opciones arrastrando. Aquí solo se guarda la ESTRUCTURA (árbol de
 * claves); el catálogo con etiquetas, iconos y permisos vive en el frontend, que
 * es lo que pinta la barra. Reordenar NO otorga acceso: el filtro por ámbito y
 * permiso sigue vivo en la barra lateral.
 */
class MenuRolController extends Controller
{
    /** Profundidad máxima permitida: grupo → subgrupo → ítem. */
    private const NIVELES = 3;

    public function index(): Response
    {
        $menus = MenuRol::query()->pluck('estructura', 'rol_id');

        return Inertia::render('Plataforma/Menu', [
            'roles' => Rol::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Rol $rol) => [
                    'id' => $rol->id,
                    'nombre' => $rol->nombre,
                    // La estructura guardada (o null → el editor parte del default).
                    'estructura' => $menus[$rol->id] ?? null,
                ]),
        ]);
    }

    public function guardar(Request $request, Rol $rol): RedirectResponse
    {
        $datos = $request->validate([
            'estructura' => ['present', 'array'],
        ]);

        MenuRol::updateOrCreate(
            ['rol_id' => $rol->id],
            ['estructura' => $this->sanear($datos['estructura'])],
        );

        return back()->with('exito', "Menú de «{$rol->nombre}» guardado.");
    }

    public function restablecer(Rol $rol): RedirectResponse
    {
        // Volver al default = borrar la fila: sin fila, la barra usa el catálogo
        // en su orden original.
        MenuRol::query()->where('rol_id', $rol->id)->delete();

        return back()->with('exito', "Menú de «{$rol->nombre}» restablecido al orden por defecto.");
    }

    /**
     * Deja el árbol en forma canónica: cada nodo con `clave` (string) y `hijos`
     * (array), recortado a NIVELES de profundidad. Descarta cualquier basura que
     * viniera de más.
     *
     * @param  array<int, mixed>  $nodos
     * @return array<int, array{clave: string, hijos: array<int, mixed>}>
     */
    private function sanear(array $nodos, int $nivel = 1): array
    {
        $limpios = [];

        foreach ($nodos as $nodo) {
            if (! is_array($nodo) || ! isset($nodo['clave']) || ! is_string($nodo['clave'])) {
                continue;
            }

            $hijos = (is_array($nodo['hijos'] ?? null) && $nivel < self::NIVELES)
                ? $this->sanear($nodo['hijos'], $nivel + 1)
                : [];

            $limpios[] = ['clave' => $nodo['clave'], 'hijos' => $hijos];
        }

        return $limpios;
    }
}
