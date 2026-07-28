<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Identidad\MenuRol;
use App\Models\Identidad\Rol;
use Illuminate\Database\Seeder;

/**
 * Disposición por defecto del menú lateral para los roles ADMINISTRADORES.
 *
 * Para un administrador el orden útil es: primero el Panel (puerta de entrada),
 * luego Académico (donde se arma la escuela) y en tercer lugar Control escolar.
 * Solo se fijan esos tres arriba; el resto de módulos se fusiona en el orden
 * del catálogo (lo hace `construir.ts` con `fusionarFaltantes`), así que agregar
 * un módulo nuevo no obliga a tocar este seeder.
 *
 * `Panel` va primero de todos modos —la barra lo fuerza en `construirNavegacion`—,
 * pero se deja explícito aquí para que la disposición guardada sea legible.
 *
 * Idempotente por `rol_id` (unique): correrlo de nuevo actualiza, no duplica.
 */
class MenuRolSeeder extends Seeder
{
    public function run(): void
    {
        $estructura = [
            ['clave' => 'panel', 'hijos' => []],
            ['clave' => 'academico', 'hijos' => []],
            ['clave' => 'escolar', 'hijos' => []],
        ];

        // Roles ADMINISTRADORES: la faceta administrativa y las direcciones
        // (ven el menú completo, donde Académico y Control escolar son lo
        // central). Se incluyen también `admin`/`super-admin` por si un tenant
        // usa ese esquema en vez del organigrama.
        $roles = Rol::query()
            ->whereIn('name', ['administrativo', 'director_general', 'director_campus', 'admin', 'super-admin'])
            ->pluck('id');

        foreach ($roles as $rolId) {
            MenuRol::updateOrCreate(
                ['rol_id' => $rolId],
                ['estructura' => $estructura, 'ocultos' => []],
            );
        }
    }
}
