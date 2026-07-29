<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * responsables (TENANT) — quien firma las certificaciones y titulaciones
 * electrónicas. Su identidad se lee del `.cer` (titular, serial, vigencias) y
 * se completa con el cargo y el título profesional.
 *
 * Una sola tabla para ambos flujos: `tipo_responsable_id` distingue
 * Certificación (1) de Titulación (2). Certificación admite 1 responsable;
 * Titulación hasta 2 — eso lo cuida el controlador, no un índice, porque es
 * regla de negocio que puede cambiar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_responsable_id')->constrained('tipos_responsable')->restrictOnDelete();

            // Identidad de la persona.
            $table->string('nombre', 255);
            $table->string('apellido_paterno', 255);
            $table->string('apellido_materno', 255)->nullable();
            $table->string('curp', 18);

            $table->foreignId('cargo_id')->nullable()->constrained('cargos')->nullOnDelete();
            $table->foreignId('titulo_profesional_id')->nullable()->constrained('titulos_profesionales')->nullOnDelete();

            // Datos leídos del certificado (.cer).
            $table->string('cer_titular', 255)->nullable();
            $table->string('cer_serial', 100)->nullable();
            $table->date('cer_vigencia_inicio')->nullable();
            $table->date('cer_vigencia_fin')->nullable();

            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsables');
    }
};
