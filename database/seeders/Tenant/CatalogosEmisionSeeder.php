<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Emision\Cargo;
use App\Models\Emision\TipoResponsable;
use App\Models\Emision\TituloProfesional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Catálogos de la certificación/titulación electrónica.
 *
 * `cargos` y `tipos_responsable` son OFICIALES (id fijo, protegidos: no se
 * editan ni eliminan). `titulos_profesionales` nace con valores comunes pero la
 * escuela lo administra desde Configuración → Catálogos.
 */
class CatalogosEmisionSeeder extends Seeder
{
    public function run(): void
    {
        // Cargos oficiales (id fijo = el del sistema origen).
        $this->fijos(Cargo::class, [
            [1, 'director', 'DIRECTOR'],
            [2, 'subdirector', 'SUBDIRECTOR'],
            [3, 'rector', 'RECTOR'],
            [4, 'vicerrector', 'VICERRECTOR'],
            [5, 'responsable_expedicion', 'RESPONSABLE DE EXPEDICIÓN'],
        ]);

        // Tipo de responsable: la lógica los conoce por estos ids.
        $this->fijos(TipoResponsable::class, [
            [TipoResponsable::CERTIFICACION, 'certificacion', 'Certificación'],
            [TipoResponsable::TITULACION, 'titulacion', 'Titulación'],
        ]);

        // Títulos profesionales de arranque (editables).
        $titulos = [
            ['Ing.', 'Ingeniero'],
            ['Lic.', 'Licenciado'],
            ['C.P.', 'Contador Público'],
            ['Mtro.', 'Maestro'],
            ['Mtra.', 'Maestra'],
            ['Dr.', 'Doctor'],
            ['Dra.', 'Doctora'],
        ];

        foreach ($titulos as [$abreviatura, $descripcion]) {
            TituloProfesional::query()->updateOrCreate(
                ['abreviatura' => $abreviatura],
                ['descripcion' => $descripcion],
            );
        }
    }

    /**
     * Siembra un catálogo con id explícito (= referencia estable) y protegido.
     *
     * @param  class-string<Model>  $modelo
     * @param  array<int, array{0: int, 1: string, 2: string}>  $filas  [id, clave, nombre]
     */
    private function fijos(string $modelo, array $filas): void
    {
        foreach ($filas as [$id, $clave, $nombre]) {
            $registro = $modelo::query()->firstOrNew(['id' => $id]);
            $registro->forceFill([
                'id' => $id,
                'clave' => $clave,
                'nombre' => $nombre,
                'protegido' => true,
            ])->save();
        }
    }
}
