<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * aspirante_asesor (TENANT) — asignación de asesores a un aspirante
 * (del legacy inter_asesor_persona).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aspirante_asesor', function (Blueprint $table) {
            $table->foreignId('aspirante_id')->constrained('aspirantes')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('asesores', 'persona_id')->cascadeOnDelete();
            $table->auditoria();

            $table->primary(['aspirante_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aspirante_asesor');
    }
};
