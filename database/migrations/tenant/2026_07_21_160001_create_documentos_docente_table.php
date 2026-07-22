<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documentos_docente (TENANT) — el expediente que el docente carga de sí mismo.
 *
 * Espeja a `expediente_documentos` (el del aspirante) y reutiliza los mismos
 * catálogos: `documentos_requeridos` para el tipo y `estados_documento` para la
 * revisión. Son el mismo problema —una persona sube comprobantes y alguien los
 * valida— y no merecen dos motores distintos.
 *
 * NO es `expedientes_laborales` del Módulo 10 (Nómina y RH, Fase 4). Aquello
 * guarda contrato, régimen fiscal, puesto y adscripciones, que captura RH y no
 * el docente. Cuando ese módulo se construya, esta tabla seguirá siendo la de
 * los documentos que el propio docente aporta; si conviene, se le agregará una
 * FK al expediente laboral.
 *
 * Único (persona_id, documento_id): volver a subir el mismo tipo de documento
 * REEMPLAZA el anterior, no acumula copias. Es lo que espera quien corrige un
 * archivo mal escaneado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_docente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('docentes', 'persona_id')->cascadeOnDelete();
            $table->foreignId('documento_id')->constrained('documentos_requeridos');
            $table->string('descripcion', 100)->nullable();
            $table->string('url', 500);
            $table->foreignId('estado_documento_id')->constrained('estados_documento');
            $table->date('vigencia')->nullable(); // algunos vencen (constancias, certificados médicos)
            $table->string('observaciones', 255)->nullable(); // por qué se rechazó
            $table->auditoria();

            $table->unique(['persona_id', 'documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_docente');
    }
};
