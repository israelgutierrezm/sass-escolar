<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * aspirante_tutor_crm (TENANT) — asignación de tutores de admisión a un
 * aspirante (del legacy inter_tutor_persona).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aspirante_tutor_crm', function (Blueprint $table) {
            $table->foreignId('aspirante_id')->constrained('aspirantes')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('tutores_crm', 'persona_id')->cascadeOnDelete();
            $table->auditoria();

            $table->primary(['aspirante_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aspirante_tutor_crm');
    }
};
