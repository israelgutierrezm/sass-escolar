<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * aspirante_promocion (TENANT) — del legacy inter_promocion_persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aspirante_promocion', function (Blueprint $table) {
            $table->foreignId('aspirante_id')->constrained('aspirantes')->cascadeOnDelete();
            $table->foreignId('promocion_id')->constrained('promociones')->cascadeOnDelete();
            $table->auditoria();

            $table->primary(['aspirante_id', 'promocion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aspirante_promocion');
    }
};
