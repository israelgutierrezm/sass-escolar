<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * etiquetas_documento (TENANT) — clasificación de documentos
 * (del legacy tr_etiquetas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etiquetas_documento', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etiquetas_documento');
    }
};
