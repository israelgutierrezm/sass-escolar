<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * campus (TENANT) — planteles de la escuela. `entidad_id` es ref lógica a
 * entidades_federativas (landlord), sin FK real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50);
            $table->string('nombre');
            $table->foreignId('tipo_campus_id')->constrained('tipos_campus');
            $table->boolean('online')->default(false);
            $table->unsignedBigInteger('entidad_id')->nullable(); // landlord, sin FK
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus');
    }
};
