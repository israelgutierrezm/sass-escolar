<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puebla `entidades_federativas.identificador` (LANDLORD) con el código OFICIAL
 * de 2 dígitos de la SEP/RENAPO ("01".."32" + "33" Extranjero), que es el
 * idEntidadFederativa del título/certificado electrónico. Antes quedaba NULL y el
 * constructor caía al id de fila ("1"), cuando la SEP exige "09" (p. ej. CDMX).
 *
 * Se mapea por la `clave` CURP de dos letras (alfabética, igual que el código
 * INEGI), verificado contra el ejemplo oficial (DF/CDMX = "09").
 */
return new class extends Migration
{
    /** clave CURP → idEntidadFederativa oficial (2 dígitos). */
    private const CODIGOS = [
        'AS' => '01', 'BC' => '02', 'BS' => '03', 'CC' => '04', 'CL' => '05',
        'CM' => '06', 'CS' => '07', 'CH' => '08', 'DF' => '09', 'DG' => '10',
        'GT' => '11', 'GR' => '12', 'HG' => '13', 'JC' => '14', 'MC' => '15',
        'MN' => '16', 'MS' => '17', 'NT' => '18', 'NL' => '19', 'OC' => '20',
        'PL' => '21', 'QT' => '22', 'QR' => '23', 'SP' => '24', 'SL' => '25',
        'SR' => '26', 'TC' => '27', 'TS' => '28', 'TL' => '29', 'VZ' => '30',
        'YN' => '31', 'ZS' => '32', 'NE' => '33',
    ];

    public function up(): void
    {
        foreach (self::CODIGOS as $clave => $codigo) {
            DB::table('entidades_federativas')->where('clave', $clave)->update(['identificador' => $codigo]);
        }
    }

    public function down(): void
    {
        DB::table('entidades_federativas')->update(['identificador' => null]);
    }
};
