<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Admisiones\SituacionAsesor;
use App\Models\Admisiones\SituacionAspirante;
use App\Models\Admisiones\SituacionTutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG del módulo de matrícula y admisiones. Idempotente
 * por clave.
 *
 * test DISC proviene del sistema legacy y no debe inventarse.
 */
class CatalogosAdmisionesSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrar(SituacionAspirante::class, [
            ['clave' => 'prospecto', 'nombre' => 'Prospecto'],
            ['clave' => 'en_proceso', 'nombre' => 'En proceso'],
            ['clave' => 'aceptado', 'nombre' => 'Aceptado'],
            ['clave' => 'rechazado', 'nombre' => 'Rechazado'],
            ['clave' => 'inscrito', 'nombre' => 'Inscrito'],
        ]);

        $this->sembrar(SituacionAsesor::class, [
            ['clave' => 'activo', 'nombre' => 'Activo'],
            ['clave' => 'inactivo', 'nombre' => 'Inactivo'],
        ]);

        $this->sembrar(SituacionTutor::class, [
            ['clave' => 'activo', 'nombre' => 'Activo'],
            ['clave' => 'inactivo', 'nombre' => 'Inactivo'],
        ]);

        $this->sembrar(EstadoDocumento::class, [
            ['clave' => 'pendiente', 'nombre' => 'Pendiente'],
            ['clave' => 'aceptado', 'nombre' => 'Aceptado'],
            ['clave' => 'rechazado', 'nombre' => 'Rechazado'],
        ]);

        $this->sembrar(SituacionAlumno::class, [
            ['clave' => 'activo', 'nombre' => 'Activo'],
            ['clave' => 'baja_temporal', 'nombre' => 'Baja temporal'],
            ['clave' => 'baja_definitiva', 'nombre' => 'Baja definitiva'],
            ['clave' => 'egresado', 'nombre' => 'Egresado'],
            ['clave' => 'titulado', 'nombre' => 'Titulado'],
            ['clave' => 'condicionado', 'nombre' => 'Condicionado'],
        ]);

        $etapas = [
            ['clave' => 'contacto_inicial', 'nombre' => 'Contacto inicial', 'orden' => 1],
            ['clave' => 'informacion_enviada', 'nombre' => 'Información enviada', 'orden' => 2],
            ['clave' => 'documentacion', 'nombre' => 'En documentación', 'orden' => 3],
            ['clave' => 'evaluacion', 'nombre' => 'En evaluación', 'orden' => 4],
            ['clave' => 'aceptado', 'nombre' => 'Aceptado', 'orden' => 5],
            ['clave' => 'inscrito', 'nombre' => 'Inscrito', 'orden' => 6],
        ];

        foreach ($etapas as $etapa) {
            EtapaCrm::query()->updateOrCreate(
                ['clave' => $etapa['clave']],
                ['nombre' => $etapa['nombre'], 'orden' => $etapa['orden']],
            );
        }
    }

    /**
     * @param  class-string<Model>  $modelo
     * @param  array<int, array{clave: string, nombre: string}>  $filas
     */
    private function sembrar(string $modelo, array $filas): void
    {
        foreach ($filas as $fila) {
            $modelo::query()->updateOrCreate(
                ['clave' => $fila['clave']],
                ['nombre' => $fila['nombre']],
            );
        }
    }
}
