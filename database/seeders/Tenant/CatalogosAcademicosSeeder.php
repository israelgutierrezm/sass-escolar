<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Academico\Area;
use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\TipoAsignatura;
use App\Models\Academico\TipoCampus;
use App\Models\Academico\TipoPeriodo;
use App\Models\Academico\TipoPlanEstudio;
use App\Models\Academico\Turno;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG del módulo de estructura académica. Se ejecuta por
 * tenant. Defaults de la spec; la escuela puede editar. Idempotente por clave.
 */
class CatalogosAcademicosSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrar(TipoCampus::class, [
            ['clave' => 'matriz', 'nombre' => 'Matriz'],
            ['clave' => 'extension', 'nombre' => 'Extensión'],
            ['clave' => 'online', 'nombre' => 'En línea'],
        ]);

        $this->sembrar(TipoPeriodo::class, [
            ['clave' => 'semestral', 'nombre' => 'Semestral'],
            ['clave' => 'cuatrimestral', 'nombre' => 'Cuatrimestral'],
            ['clave' => 'trimestral', 'nombre' => 'Trimestral'],
            ['clave' => 'anual', 'nombre' => 'Anual'],
            ['clave' => 'modular', 'nombre' => 'Modular'],
        ]);

        $this->sembrar(TipoPlanEstudio::class, [
            ['clave' => 'escolarizado', 'nombre' => 'Escolarizado'],
            ['clave' => 'no_escolarizado', 'nombre' => 'No escolarizado'],
            ['clave' => 'mixto', 'nombre' => 'Mixto'],
        ]);

        $this->sembrar(TipoAsignatura::class, [
            ['clave' => 'obligatoria', 'nombre' => 'Obligatoria'],
            ['clave' => 'optativa', 'nombre' => 'Optativa'],
            ['clave' => 'seminario', 'nombre' => 'Seminario'],
            ['clave' => 'taller', 'nombre' => 'Taller'],
        ]);

        $this->sembrar(ClasificacionAsignatura::class, [
            ['clave' => 'teorica', 'nombre' => 'Teórica'],
            ['clave' => 'practica', 'nombre' => 'Práctica'],
            ['clave' => 'teorico_practica', 'nombre' => 'Teórico-práctica'],
        ]);

        $this->sembrar(Area::class, [
            ['clave' => 'basica', 'nombre' => 'Área básica'],
            ['clave' => 'disciplinar', 'nombre' => 'Área disciplinar'],
            ['clave' => 'complementaria', 'nombre' => 'Área complementaria'],
        ]);

        $this->sembrar(AutorizacionReconocimiento::class, [
            ['clave' => 'rvoe_federal', 'nombre' => 'RVOE Federal (SEP)'],
            ['clave' => 'rvoe_estatal', 'nombre' => 'RVOE Estatal'],
            ['clave' => 'autonoma', 'nombre' => 'Universidad Autónoma'],
            ['clave' => 'incorporacion_uni', 'nombre' => 'Incorporación a universidad'],
        ]);

        $this->sembrar(Turno::class, [
            ['clave' => 'matutino', 'nombre' => 'Matutino'],
            ['clave' => 'vespertino', 'nombre' => 'Vespertino'],
            ['clave' => 'mixto', 'nombre' => 'Mixto'],
            ['clave' => 'sabatino', 'nombre' => 'Sabatino'],
        ]);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelo
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
