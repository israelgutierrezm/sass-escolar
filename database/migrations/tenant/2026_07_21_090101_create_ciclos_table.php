<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ciclos (TENANT) — periodo escolar. Una escuela puede tener varios ciclos
 * abiertos a la vez; normalmente cada campus carga los suyos, pero se permite
 * un ciclo compartido (campus_id NULL = global de la escuela).
 *
 * Las ventanas (inscripción, altas/bajas, captura de calificaciones) gobiernan
 * las validaciones de la inscripción autogestiva y del asentamiento de actas.
 *
 * NOTA DE SPEC: la especificación listaba `fecha_inicio`/`fecha_fin` Y ADEMÁS
 * `inicio`/`fin` con la misma semántica ("Inicio del ciclo"/"Fin del ciclo").
 * Es una duplicación de la tabla original; aquí se conserva un solo par,
 * `fecha_inicio`/`fecha_fin`, por consistencia con el resto del esquema
 * (fecha_ingreso, fecha_rvoe, fecha_nacimiento...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciclos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained('campus'); // NULL = ciclo global
            $table->string('clave', 50); // p.ej. 2026-2027/1
            $table->string('nombre', 120);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->foreignId('situacion_id')->constrained('situaciones_ciclo');
            $table->date('inscripcion_desde')->nullable();
            $table->date('inscripcion_hasta')->nullable();
            $table->date('altas_bajas_hasta')->nullable();
            $table->date('captura_calif_hasta')->nullable();
            $table->auditoria();

            $table->unique(['campus_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ciclos');
    }
};
