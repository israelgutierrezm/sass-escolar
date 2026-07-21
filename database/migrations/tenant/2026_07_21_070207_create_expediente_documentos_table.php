<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expediente_documentos (TENANT) — un documento entregado por el ASPIRANTE,
 * con su estado de revisión (del legacy inter_expediente). El expediente del
 * alumno ya inscrito es la tabla `expedientes`, aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expediente_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aspirante_id')->constrained('aspirantes')->cascadeOnDelete();
            $table->foreignId('documento_id')->constrained('documentos_requeridos');
            $table->foreignId('carrera_id')->nullable()->constrained('carreras');
            $table->string('descripcion', 100)->nullable();
            $table->string('url', 500); // S3 o Laserfiche
            $table->foreignId('estado_documento_id')->constrained('estados_documento');
            $table->boolean('copia_certificada')->default(false);
            $table->boolean('documento_fisico')->default(false);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expediente_documentos');
    }
};
