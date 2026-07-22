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
 *     menús y permisos: director general, encargado de admisiones, auxiliar de
 *     control escolar... Heredan los permisos de su faceta.
 *
 * La escuela puede agregar más roles funcionales sin tocar código.
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

        foreach ($facetas as [$clave, $nombre]) {
            $ids[$clave] = $this->rol($clave, $nombre)->getKey();
        }

        // Roles funcionales dentro de la faceta administrativa.
        $administrativos = [
            ['director_general', 'Director general'],
            ['director_campus', 'Director de campus'],
            ['encargado_admisiones', 'Encargado de admisiones'],
            ['auxiliar_admisiones', 'Auxiliar de admisiones'],
            // Personal de promoción: capta prospectos en la calle, ferias y
            // referidos, y les da seguimiento. Ve SOLO los suyos.
            ['promotor', 'Promotor'],
            ['encargado_control_escolar', 'Encargado de control escolar'],
            ['auxiliar_control_escolar', 'Auxiliar de control escolar'],
            ['encargado_finanzas', 'Encargado de finanzas'],
            ['auxiliar_finanzas', 'Auxiliar de finanzas'],
        ];

        foreach ($administrativos as [$clave, $nombre]) {
            $this->rol($clave, $nombre, $ids['administrativo']);
        }

        // Rol funcional dentro de docencia: coordinador de academia.
        $this->rol('coordinador_academia', 'Coordinador de academia', $ids['docente']);
    }

    private function rol(string $clave, string $nombre, ?int $padreId = null): Rol
    {
        return Rol::query()->updateOrCreate(
            ['name' => $clave, 'guard_name' => 'web'],
            ['nombre' => $nombre, 'rol_padre_id' => $padreId],
        );
    }
}
