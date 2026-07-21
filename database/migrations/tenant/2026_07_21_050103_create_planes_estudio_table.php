<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * planes_estudio (TENANT) — un plan pertenece a una carrera; una carrera tiene
 * 1..N planes. Campos de titulación tomados de academyx. Un plan viejo y uno
 * nuevo coexisten (columna `vigente`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes_estudio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrera_id')->constrained('carreras');
            $table->string('clave', 50);
            $table->string('abreviacion', 50)->nullable(); // requerido en título
            $table->string('nombre');
            $table->string('rvoe', 100);
            $table->date('fecha_rvoe')->nullable();
            $table->foreignId('autorizacion_reconocimiento_id')->constrained('autorizaciones_reconocimiento');
            $table->foreignId('tipo_periodo_id')->constrained('tipos_periodo');
            $table->integer('total_periodos')->nullable();
            $table->integer('calificacion_minima');
            $table->integer('calificacion_maxima');
            $table->integer('calificacion_minima_aprobatoria');
            $table->float('minimo_creditos');
            $table->integer('minimo_asignaturas')->nullable();
            $table->float('total_creditos');
            $table->string('curp_responsable', 18)->nullable(); // responsable del plan ante SEP
            $table->string('clave_matricula', 100)->nullable(); // regla de generación de matrícula
            $table->string('clave_matricula_consecutivo', 100)->nullable();
            $table->boolean('vigente')->default(true);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes_estudio');
    }
};
