<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El descriptor de una asignatura deja de ser una simple marca y pasa a
 * GUARDAR CONTENIDO: cada descriptor elegido para una materia lleva su propio
 * texto enriquecido (HTML de un editor), que después se muestra en otro
 * apartado. El catálogo `descriptores` sigue siendo solo el título; el contenido
 * vive por (asignatura, descriptor) en el pivote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asignatura_descriptor', function (Blueprint $table) {
            $table->longText('contenido')->nullable()->after('descriptor_id');
        });
    }

    public function down(): void
    {
        Schema::table('asignatura_descriptor', function (Blueprint $table) {
            $table->dropColumn('contenido');
        });
    }
};
