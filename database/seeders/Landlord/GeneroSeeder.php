<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\Genero;
use Illuminate\Database\Seeder;

/**
 * Catálogo CENTRAL de género. Por decisión del sistema son SOLO dos, con id
 * fijo (= clave) y `protegido`: no se editan ni se eliminan. Es catálogo
 * compartido por todas las escuelas. La derivación de sexo se apoya en estos
 * nombres (ver App\Services\IdentidadPersona::GENERO_A_SEXO).
 */
class GeneroSeeder extends Seeder
{
    private const GENEROS = [
        [250, 'MUJER'],
        [251, 'HOMBRE'],
    ];

    public function run(): void
    {
        foreach (self::GENEROS as [$id, $nombre]) {
            $registro = Genero::query()->firstOrNew(['id' => $id]);
            $registro->forceFill([
                'id' => $id,
                'clave' => (string) $id,
                'nombre' => $nombre,
                'protegido' => true,
            ])->save();
        }

        // Cualquier otro género queda fuera: el catálogo es exactamente estos dos.
        Genero::query()->whereNotIn('id', array_column(self::GENEROS, 0))->delete();
    }
}
