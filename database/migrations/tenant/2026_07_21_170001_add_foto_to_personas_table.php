<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto vive en `personas`, no en `usuarios`.
 *
 * `usuarios.url_perfil` ya existía, pero es del avatar de la CUENTA y no todo
 * el mundo tiene cuenta: un alumno de primer ingreso, un docente recién dado de
 * alta o un tutor pueden no tener usuario todavía, y aun así su ficha necesita
 * cara. La foto es de la persona, igual que su nombre.
 *
 * Se guarda la ruta en el disco privado `local` (sufijado por escuela por
 * stancl/tenancy), nunca en public/: es un dato personal sujeto a la LFPDPPP y
 * se sirve por ruta autenticada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->string('foto_url', 500)->nullable()->after('celular');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('foto_url');
        });
    }
};
