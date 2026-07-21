<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Formularios\FormularioObligatoriedad;
use App\Models\Formularios\FormularioVisibilidad;
use App\Models\Formularios\TipoAntecedenteAcademico;
use App\Models\Formularios\TipoCampo;
use Illuminate\Database\Seeder;

/**
 * Catálogos TENANT-CONFIG del motor de formularios dinámicos. Idempotente
 * por clave.
 */
class CatalogosFormulariosSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrar(TipoCampo::class, [
            ['clave' => 'texto', 'nombre' => 'Texto'],
            ['clave' => 'textarea', 'nombre' => 'Texto largo'],
            ['clave' => 'numero', 'nombre' => 'Número'],
            ['clave' => 'fecha', 'nombre' => 'Fecha'],
            ['clave' => 'select', 'nombre' => 'Lista desplegable'],
            ['clave' => 'multiselect', 'nombre' => 'Selección múltiple'],
            ['clave' => 'radio', 'nombre' => 'Opción única'],
            ['clave' => 'checkbox', 'nombre' => 'Casilla de verificación'],
            ['clave' => 'documento', 'nombre' => 'Documento'],
            ['clave' => 'email', 'nombre' => 'Correo electrónico'],
            ['clave' => 'telefono', 'nombre' => 'Teléfono'],
        ]);

        $this->sembrar(FormularioObligatoriedad::class, [
            ['clave' => 'obligatorio', 'nombre' => 'Obligatorio'],
            ['clave' => 'opcional', 'nombre' => 'Opcional'],
            ['clave' => 'condicional', 'nombre' => 'Condicional'],
        ]);

        $this->sembrar(FormularioVisibilidad::class, [
            ['clave' => 'alumno', 'nombre' => 'Alumno'],
            ['clave' => 'admin', 'nombre' => 'Administrativo'],
            ['clave' => 'ambos', 'nombre' => 'Ambos'],
        ]);

        $this->sembrar(TipoAntecedenteAcademico::class, [
            ['clave' => 'secundaria', 'nombre' => 'Secundaria'],
            ['clave' => 'bachillerato', 'nombre' => 'Bachillerato'],
            ['clave' => 'tecnico_superior', 'nombre' => 'Técnico Superior Universitario'],
            ['clave' => 'licenciatura', 'nombre' => 'Licenciatura'],
            ['clave' => 'especialidad', 'nombre' => 'Especialidad'],
            ['clave' => 'maestria', 'nombre' => 'Maestría'],
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
