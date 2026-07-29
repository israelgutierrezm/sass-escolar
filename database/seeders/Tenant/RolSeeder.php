<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Identidad\Rol;
use Illuminate\Database\Seeder;

/**
 * Catálogo de roles (TENANT-CONFIG), en dos niveles:
 *
 *  1. FACETAS (sin padre) — lo que una persona ES: administrativo, docente,
 *     alumno, aspirante, tutor educativo, padre de familia.
 *  2. ROLES FUNCIONALES — cuelgan de una faceta y son los que de verdad acotan
 *     menús y permisos. Heredan los permisos de su faceta.
 *
 * De los administrativos SOLO se siembra `director_general` (el administrador
 * de la escuela, con todos los permisos de la faceta). Las demás variantes
 * —encargados, auxiliares, promotor, director de campus, coordinador de
 * academia— las crea cada escuela según su organigrama, sin tocar código.
 * El alcance por campus ("director del campus Norte") NO se define aquí: va en
 * `persona_rol.campus_id` al asignar el rol a la persona.
 *
 * Idempotente por (name, guard_name).
 */
class RolSeeder extends Seeder
{
    public function run(): void
    {
        $facetas = [
            ['administrativo', 'Administrativo'],
            ['docente', 'Docente'],
            ['alumno', 'Alumno'],
            ['aspirante', 'Aspirante'],
            ['tutor_educativo', 'Tutor educativo'],
            ['padre_familia', 'Padre o tutor familiar'],
        ];

        $ids = [];

        // Las facetas son roles del sistema: `protegido` para que no se puedan
        // eliminar ni recolgar de otro rol (hay código que las conoce por nombre).
        foreach ($facetas as [$clave, $nombre]) {
            $ids[$clave] = $this->rol($clave, $nombre, protegido: true)->getKey();
        }

        // ÚNICO rol administrativo funcional que se siembra: Director general.
        // Es el administrador de la escuela (se le asigna al crear el tenant),
        // recibe TODOS los permisos de la faceta administrativa (ver
        // PermisoSeeder) y es `protegido` para que no se pueda eliminar: siempre
        // debe existir. Las demás variantes administrativas —director de campus,
        // encargados, auxiliares, promotor, coordinador de academia— las crea
        // cada escuela según su organigrama desde /plataforma/roles; no se
        // siembran.
        $this->rol('director_general', 'Director general', $ids['administrativo'], protegido: true);
    }

    private function rol(string $clave, string $nombre, ?int $padreId = null, bool $protegido = false): Rol
    {
        return Rol::query()->updateOrCreate(
            ['name' => $clave, 'guard_name' => 'web'],
            ['nombre' => $nombre, 'rol_padre_id' => $padreId, 'protegido' => $protegido],
        );
    }
}
