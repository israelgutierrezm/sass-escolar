<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Plataforma\Modulo;
use Illuminate\Database\Seeder;

/**
 * Siembra el catálogo de los 13 módulos del sistema (TENANT-CONFIG). Se ejecuta
 * en el contexto de cada tenant (vía DatabaseSeeder). Idempotente por clave.
 *
 * Encender/apagar un módulo por escuela es dato de `modulos_activos`, no de
 * este catálogo.
 */
class ModuloSeeder extends Seeder
{
    public function run(): void
    {
        $modulos = [
            ['clave' => 'identidad', 'nombre' => 'Identidad'],
            ['clave' => 'academico', 'nombre' => 'Estructura académica'],
            ['clave' => 'formularios', 'nombre' => 'Formularios dinámicos'],
            ['clave' => 'admisiones', 'nombre' => 'Matrícula y admisiones'],
            ['clave' => 'control_escolar', 'nombre' => 'Control escolar'],
            ['clave' => 'asistencia', 'nombre' => 'Asistencia y reloj checador'],
            ['clave' => 'finanzas', 'nombre' => 'Finanzas'],
            ['clave' => 'lms', 'nombre' => 'LMS'],
            ['clave' => 'titulacion', 'nombre' => 'Titulación y certificación SEP'],
            ['clave' => 'nomina', 'nombre' => 'Nómina y recursos humanos'],
            ['clave' => 'bolsa_trabajo', 'nombre' => 'Bolsa de trabajo'],
            ['clave' => 'movilidad', 'nombre' => 'Movilidad e intercambios'],
            ['clave' => 'familia', 'nombre' => 'Portal de familiares'],
        ];

        foreach ($modulos as $modulo) {
            Modulo::query()->updateOrCreate(
                ['clave' => $modulo['clave']],
                ['nombre' => $modulo['nombre']],
            );
        }
    }
}
