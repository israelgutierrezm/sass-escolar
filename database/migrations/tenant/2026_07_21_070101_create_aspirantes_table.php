<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * aspirantes (TENANT) — el CRM de admisión, proceso por pasos (del legacy
 * tr_aspirante). Lleva banderas de avance para que el admin vea en qué paso va
 * cada prospecto. Al aceptarse se crea matricula_oferta con la MISMA
 * persona_id: cero recaptura.
 *
 * `ciclo_ingreso_id` apunta a `ciclos`, que pertenece al Módulo 5 (Fase 2) y
 * todavía no existe: por eso queda como columna sin FK. El constraint se
 * agregará en una migración de seguimiento cuando se cree `ciclos`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aspirantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas');
            $table->foreignId('oferta_interes_id')->nullable()->constrained('oferta');
            $table->foreignId('campus_id')->nullable()->constrained('campus');
            $table->string('clave_aspirante', 50)->nullable();
            $table->foreignId('situacion_id')->constrained('situaciones_aspirante');
            $table->smallInteger('paso')->default(0); // paso del alta (legacy)
            $table->boolean('acepto_terminos')->default(false);
            $table->boolean('info_personal_completa')->default(false);
            $table->boolean('cleaver_completo')->default(false);
            $table->boolean('validado_admin')->default(false);
            $table->string('origen', 80)->nullable(); // campaña, referido, web...
            $table->unsignedBigInteger('ciclo_ingreso_id')->nullable(); // FK pendiente (Módulo 5)
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aspirantes');
    }
};
