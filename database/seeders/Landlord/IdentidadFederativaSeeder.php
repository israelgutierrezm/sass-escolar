<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\IdentidadFederativa;
use App\Models\Landlord\Pais;
use Illuminate\Database\Seeder;

/**
 * El catálogo federativo para PERSONAS (lugar de nacimiento): 32 entidades de
 * México + NE = «Nacido en el extranjero».
 *
 * Gemelo de `EntidadFederativaSeeder` (lugares); comparten claves y difieren
 * solo en el texto del 33 —un plantel está en el extranjero, una persona nace
 * en él—. Idempotente por (pais_id, clave).
 */
class IdentidadFederativaSeeder extends Seeder
{
    public function run(): void
    {
        $mexico = Pais::query()->where('clave_iso', 'MEX')->first();

        if ($mexico === null) {
            $this->call(PaisSeeder::class);
            $mexico = Pais::query()->where('clave_iso', 'MEX')->firstOrFail();
        }

        $entidades = [
            'AS' => 'Aguascalientes',
            'BC' => 'Baja California',
            'BS' => 'Baja California Sur',
            'CC' => 'Campeche',
            'CL' => 'Coahuila de Zaragoza',
            'CM' => 'Colima',
            'CS' => 'Chiapas',
            'CH' => 'Chihuahua',
            'DF' => 'Ciudad de México',
            'DG' => 'Durango',
            'GT' => 'Guanajuato',
            'GR' => 'Guerrero',
            'HG' => 'Hidalgo',
            'JC' => 'Jalisco',
            'MC' => 'México',
            'MN' => 'Michoacán de Ocampo',
            'MS' => 'Morelos',
            'NT' => 'Nayarit',
            'NL' => 'Nuevo León',
            'OC' => 'Oaxaca',
            'PL' => 'Puebla',
            'QT' => 'Querétaro',
            'QR' => 'Quintana Roo',
            'SP' => 'San Luis Potosí',
            'SL' => 'Sinaloa',
            'SR' => 'Sonora',
            'TC' => 'Tabasco',
            'TS' => 'Tamaulipas',
            'TL' => 'Tlaxcala',
            'VZ' => 'Veracruz de Ignacio de la Llave',
            'YN' => 'Yucatán',
            'ZS' => 'Zacatecas',
            'NE' => 'Nacido en el extranjero',
        ];

        foreach ($entidades as $clave => $nombre) {
            IdentidadFederativa::query()->updateOrCreate(
                ['pais_id' => $mexico->id, 'clave' => $clave],
                ['nombre' => $nombre],
            );
        }
    }
}
