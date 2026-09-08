<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citas familia–docente (TENANT). Ver `docs/plan-citas-familia-docente.md`.
 *
 * `disponibilidad_cita_docente` — las ventanas de atención a padres que el
 * docente ofrece. SEPARADA de `disponibilidad_docente` (esa es cuándo puede DAR
 * CLASE): una cita con un padre ocurre justo cuando el docente NO está en aula.
 *
 * `citas_familia_docente` — la cita: quién la pide, sobre qué hijo, con qué
 * docente, cuándo, cómo y en qué estado. Es un HECHO con dos partes: la familia
 * solicita, el docente confirma o rechaza, y no se encima con otra confirmada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('disponibilidad_cita_docente')) {
            Schema::create('disponibilidad_cita_docente', function (Blueprint $tabla): void {
                $tabla->id();
                $tabla->foreignId('persona_id')->constrained('docentes', 'persona_id')->cascadeOnDelete();

                $tabla->smallInteger('dia_semana'); // 1 = lunes … 7 = domingo
                $tabla->time('hora_inicio');
                $tabla->time('hora_fin');

                // presencial | en_linea | telefonica — una ventana, una modalidad.
                $tabla->string('modalidad', 20);

                // Cuánto dura cada cita dentro de la ventana.
                $tabla->smallInteger('duracion_min')->default(20);

                // Dónde: «Sala de maestros», o la nota para quien llega.
                $tabla->string('lugar', 200)->nullable();

                $tabla->auditoria();

                $tabla->index(['persona_id', 'dia_semana']);
            });
        }

        if (Schema::hasTable('citas_familia_docente')) {
            return;
        }

        Schema::create('citas_familia_docente', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('docente_persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->foreignId('alumno_persona_id')->constrained('personas')->cascadeOnDelete();
            $tabla->foreignId('solicitante_persona_id')->constrained('personas')->cascadeOnDelete();

            // El servidor calcula `fin` de la duración de la ventana: nunca se
            // cree del cliente. Los dos se guardan para medir el traslape.
            $tabla->dateTime('inicio');
            $tabla->dateTime('fin');

            $tabla->string('modalidad', 20);
            $tabla->text('motivo'); // para qué quiere verse la familia
            $tabla->string('lugar', 200)->nullable();

            // solicitada | confirmada | rechazada | cancelada | realizada | no_asistio
            $tabla->string('estado', 20)->default('solicitada');

            // La nota del docente al confirmar o rechazar (puede sugerir otra hora).
            $tabla->text('respuesta')->nullable();

            $tabla->auditoria();

            // Para listar la agenda del docente y las citas de un hijo, y para
            // buscar traslapes de un docente por rango de fecha.
            $tabla->index(['docente_persona_id', 'estado', 'inicio']);
            $tabla->index(['alumno_persona_id', 'inicio']);
            $tabla->index(['solicitante_persona_id', 'inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citas_familia_docente');
        Schema::dropIfExists('disponibilidad_cita_docente');
    }
};
