<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La entidad federativa de expedición del título NO se captura: es la entidad del
 * campus donde se cursó (la misma que ya usa el certificado). Se elimina la
 * columna manual de `titulo_modalidad`; el constructor del XML la toma del campus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('titulo_modalidad', 'entidad_federativa_id')) {
            Schema::table('titulo_modalidad', fn (Blueprint $t) => $t->dropColumn('entidad_federativa_id'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('titulo_modalidad', 'entidad_federativa_id')) {
            Schema::table('titulo_modalidad', fn (Blueprint $t) => $t->unsignedBigInteger('entidad_federativa_id')->nullable()->after('fecha_terminacion_carrera'));
        }
    }
};
