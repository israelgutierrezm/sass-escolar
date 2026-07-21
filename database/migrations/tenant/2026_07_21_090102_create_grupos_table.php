<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * grupos (TENANT) — contenedor de materias dentro de un ciclo y un campus.
 * `grupo_origen_id` permite clonar/derivar un grupo de otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ciclo_id')->constrained('ciclos');
            $table->foreignId('campus_id')->constrained('campus');
            $table->foreignId('plan_id')->nullable()->constrained('planes_estudio');
            $table->string('clave', 70);
            $table->string('nombre', 200)->nullable();
            $table->integer('cupo')->nullable();
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->foreignId('situacion_id')->constrained('situaciones_grupo');
            $table->foreignId('grupo_origen_id')->nullable()->constrained('grupos');
            $table->auditoria();

            $table->unique(['ciclo_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupos');
    }
};
