<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documento_etiqueta (TENANT) — pivote documento requerido ↔ etiqueta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_etiqueta', function (Blueprint $table) {
            $table->foreignId('documento_id')->constrained('documentos_requeridos')->cascadeOnDelete();
            $table->foreignId('etiqueta_id')->constrained('etiquetas_documento')->cascadeOnDelete();
            $table->auditoria();

            $table->primary(['documento_id', 'etiqueta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_etiqueta');
    }
};
