<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * asesores (TENANT) — rol materializado del CRM: da seguimiento comercial al
 * aspirante. Es una persona del sistema (PK = persona_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asesores', function (Blueprint $table) {
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('clave_asesor', 50)->nullable();
            $table->foreignId('situacion_id')->constrained('situaciones_asesor');
            $table->auditoria();

            $table->primary('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asesores');
    }
};
