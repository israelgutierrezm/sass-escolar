<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expedientes (TENANT) — documentos del alumno YA inscrito, por oferta.
 * (El expediente del aspirante es expediente_documentos, del sub-bloque 4c.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('ruta', 500); // S3 o URI de Laserfiche
            $table->string('laserfiche_entry_id', 50)->nullable();
            $table->string('comentario', 500)->nullable();
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expedientes');
    }
};
