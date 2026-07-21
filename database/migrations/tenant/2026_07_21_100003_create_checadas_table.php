<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * checadas (TENANT) — fichaje del reloj, para TODAS las poblaciones: docentes,
 * administrativos y alumnos. Cuelga de `personas`, no de un rol concreto.
 *
 * Deliberadamente SEPARADA de `asistencia_clase`: esto es presencia laboral /
 * de acceso al plantel, y es lo que consumirá Nómina (Fase 4) para calcular
 * horas e incidencias. La asistencia académica que afecta faltas y
 * calificación es otra tabla.
 *
 * `dispositivo_id` es nullable para permitir capturas manuales sin dispositivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos_checador')->nullOnDelete();
            $table->string('tipo_movimiento', 20); // entrada / salida
            $table->dateTime('momento');
            $table->string('origen', 30); // qr / biometrico / geocerca / manual
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->auditoria();

            $table->index(['persona_id', 'momento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checadas');
    }
};
