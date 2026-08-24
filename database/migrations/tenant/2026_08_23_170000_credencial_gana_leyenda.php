<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La credencial gana una LEYENDA para el reverso.
 *
 * `vigencia` ya existía —«Vigente hasta julio 2027»— pero es una frase corta
 * sobre una fecha. Una leyenda es el texto institucional que va al dorso de casi
 * cualquier gafete: «personal e intransferible, en caso de extravío repórtelo
 * a…». Son dos cosas distintas y por eso dos columnas: mezclarlas obligaría a la
 * escuela a meter el aviso legal dentro de la línea de la vigencia.
 *
 * ── Por qué 400 y no 120 como `vigencia` ──────────────────────────────────
 * La vigencia es una fecha con su verbo; una leyenda son un par de oraciones.
 * El compositor ya parte el texto en renglones para que quepa en su caja, así
 * que el largo lo limita el sentido, no el dibujo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('credenciales_rol', 'leyenda')) {
            return;
        }

        Schema::table('credenciales_rol', function (Blueprint $table) {
            $table->string('leyenda', 400)->nullable()->after('vigencia');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('credenciales_rol', 'leyenda')) {
            Schema::table('credenciales_rol', fn (Blueprint $table) => $table->dropColumn('leyenda'));
        }
    }
};
