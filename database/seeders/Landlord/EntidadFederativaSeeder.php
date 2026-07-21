<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Models\Landlord\EntidadFederativa;
use App\Models\Landlord\Pais;
use Illuminate\Database\Seeder;

/**
 * Las 32 entidades federativas de México + NE (nacido en el extranjero).
 * La `clave` es el código de dos letras de RENAPO/CURP, usado en el título
 * electrónico SEP y para cross-validar la CURP. Idempotente por (pais_id, clave).
 */
class EntidadFederativaSeeder extends Seeder
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
            'NE' => 'Nacido en el Extranjero',
        ];

        foreach ($entidades as $clave => $nombre) {
            EntidadFederativa::query()->updateOrCreate(
                ['pais_id' => $mexico->id, 'clave' => $clave],
                ['nombre' => $nombre],
            );
        }
    }
}
