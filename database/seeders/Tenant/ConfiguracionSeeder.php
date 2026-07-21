<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Plataforma\Configuracion;
use App\Services\GeneradorFolioActa;
use Illuminate\Database\Seeder;

/**
 * Valores por defecto de `configuraciones` (TENANT).
 *
 * Se siembran aunque el código ya tenga un respaldo interno: una palanca que
 * no existe como fila es una palanca que nadie encuentra. Sembrada, aparece en
 * la administración de configuración y la escuela puede cambiarla sin tocar
 * código.
 *
 * Idempotente por clave: si la escuela ya la ajustó, no se le pisa el valor.
 */
class ConfiguracionSeeder extends Seeder
{
    /** @var array<int, array{clave: string, valor: string, tipo_dato: string, descripcion: string}> */
    private const VALORES = [
        [
            'clave' => GeneradorFolioActa::CLAVE_FORMATO,
            'valor' => 'ACT-{AAAA}-{#####}',
            'tipo_dato' => 'string',
            'descripcion' => 'Formato del folio del acta. Tokens: {AAAA} {AA} {CAMPUS} {CICLO} y {#####} (el padding lo da la cantidad de #).',
        ],
        [
            'clave' => GeneradorFolioActa::CLAVE_AMBITO,
            'valor' => 'anio',
            'tipo_dato' => 'string',
            'descripcion' => 'Cada cuánto reinicia el consecutivo del folio: global, anio, campus o ciclo.',
        ],
    ];

    public function run(): void
    {
        foreach (self::VALORES as $fila) {
            Configuracion::query()->firstOrCreate(
                ['clave' => $fila['clave']],
                [
                    'valor' => $fila['valor'],
                    'tipo_dato' => $fila['tipo_dato'],
                    'descripcion' => $fila['descripcion'],
                ],
            );
        }
    }
}
