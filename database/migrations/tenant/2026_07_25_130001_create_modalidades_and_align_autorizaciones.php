<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos catálogos para el módulo de Configuración de Académico.
 *
 * 1) `modalidades` (TENANT): presencial / en línea / mixta. Hoy la modalidad de
 *    una oferta es un string suelto; tener el catálogo es el primer paso para
 *    que después sea una referencia y una selección múltiple. Por ahora solo se
 *    crea y se siembra; la oferta se migra en la parte B.
 *
 * 2) Se ALINEAN las opciones de `autorizaciones_reconocimiento` a las nueve que
 *    pidió el cliente. Additive: se AGREGAN las que faltan y se conservan las
 *    que ya existían (los planes pueden estar apuntándolas). Podar las que
 *    sobren es trabajo de la pantalla de catálogos, donde se ve qué está en
 *    uso; hacerlo a ciegas aquí rompería un plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modalidades', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('clave', 50);
            $tabla->string('nombre');
            $tabla->auditoria();

            $tabla->unique(['clave', 'deleted_at']);
        });

        $modalidades = [
            'presencial' => 'Presencial',
            'en_linea' => 'En línea',
            'mixta' => 'Mixta',
        ];

        foreach ($modalidades as $clave => $nombre) {
            DB::table('modalidades')->insert([
                'clave' => $clave, 'nombre' => $nombre, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Las nueve de «Autorización o Reconocimiento». Se insertan solo las que
        // falten (por clave), sin tocar las existentes.
        $autorizaciones = [
            'rvoe_federal' => 'RVOE Federal',
            'rvoe_estatal' => 'RVOE Estatal',
            'autorizacion_federal' => 'Autorización Federal',
            'autorizacion_estatal' => 'Autorización Estatal',
            'acta_sesion' => 'Acta de Sesión',
            'acuerdo_incorporacion' => 'Acuerdo de Incorporación',
            'acuerdo_secretarial_sep' => 'Acuerdo Secretarial SEP',
            'decreto_creacion' => 'Decreto de Creación',
            'otro' => 'Otro',
        ];

        foreach ($autorizaciones as $clave => $nombre) {
            $existe = DB::table('autorizaciones_reconocimiento')->where('clave', $clave)->exists();

            if (! $existe) {
                DB::table('autorizaciones_reconocimiento')->insert([
                    'clave' => $clave, 'nombre' => $nombre, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modalidades');
        // Las autorizaciones agregadas NO se borran: pueden haber quedado
        // referenciadas por un plan. Revertir a mano si hiciera falta.
    }
};
