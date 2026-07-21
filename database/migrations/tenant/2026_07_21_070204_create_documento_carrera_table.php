<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documento_carrera (TENANT) — qué documentos exige cada carrera
 * (del legacy inter_documento_carrera).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_carrera', function (Blueprint $table) {
            $table->foreignId('documento_id')->constrained('documentos_requeridos')->cascadeOnDelete();
            $table->foreignId('carrera_id')->constrained('carreras')->cascadeOnDelete();
            $table->auditoria();

            $table->primary(['documento_id', 'carrera_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_carrera');
    }
};
