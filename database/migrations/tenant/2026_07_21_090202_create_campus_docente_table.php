<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * campus_docente (TENANT) — un docente puede estar ligado a 1..N campus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_docente', function (Blueprint $table) {
            $table->foreignId('campus_id')->constrained('campus')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('docentes', 'persona_id')->cascadeOnDelete();
            $table->auditoria();

            $table->primary(['campus_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_docente');
    }
};
