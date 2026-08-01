<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat de la materia y foros de discusión.
 *
 * ── El chat cuelga de la MATERIA IMPARTIDA ─────────────────────────────────
 * No de las personas. Una conversación entre Adriana y Mateo en Programación no
 * es la misma que en Cálculo aunque sean los mismos dos: son dos materias, dos
 * contextos y dos hilos que nadie querría mezclados. Además es lo que hace
 * cumplible «mientras tenga la materia activa» —lo que pidió el usuario—: al
 * cerrarse la materia se cierra su chat, sin tener que rastrear qué mensajes de
 * una bandeja global pertenecían a qué curso.
 *
 * ── Dos tipos y no una tabla de participantes ──────────────────────────────
 * El canal del grupo tiene por participantes a los inscritos y a los docentes:
 * ya está en la base y una tabla intermedia sería una copia que se desincroniza
 * cada vez que alguien se da de baja. La conversación directa son exactamente
 * dos personas, y van en la propia fila. Lo único que sí hace falta guardar por
 * persona es hasta dónde leyó.
 *
 * ── El foro es una ACTIVIDAD ───────────────────────────────────────────────
 * `foro_temas` cuelga de `actividades`, no de una tabla propia de foros. Así
 * hereda fechas, ponderación y amarre al parcial de lo que ya existe, y
 * participar puede calificarse por el mismo camino que una tarea. Un foro con
 * su propio esquema de fechas y su propia calificación habría sido un segundo
 * sistema de actividades conviviendo con el primero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo')->cascadeOnDelete();

            $table->string('tipo', 10); // grupo | directa

            /*
             * Solo en las directas. Se guardan ORDENADAS por id (a < b) para
             * que la misma pareja no pueda abrir dos conversaciones según quién
             * escriba primero.
             */
            $table->foreignId('persona_a_id')->nullable()->constrained('personas')->cascadeOnDelete();
            $table->foreignId('persona_b_id')->nullable()->constrained('personas')->cascadeOnDelete();

            // Se toca en cada mensaje: ordenar la lista de conversaciones por
            // actividad reciente sin un MAX() sobre toda la tabla de mensajes.
            $table->timestamp('ultimo_mensaje_en')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['asignatura_grupo_id', 'tipo', 'persona_a_id', 'persona_b_id'], 'conversacion_unica');
            $table->index(['asignatura_grupo_id', 'ultimo_mensaje_en']);
        });

        Schema::create('mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('conversaciones')->cascadeOnDelete();
            // De la PERSONA, no del usuario: sigue teniendo sentido si su cuenta
            // desaparece. Mismo criterio que `asistencia_clase.registrada_por`.
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            $table->text('cuerpo');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversacion_id', 'id']);
        });

        Schema::create('conversacion_lecturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('conversaciones')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            // Hasta qué mensaje leyó. Guardar el id y no una fecha evita que un
            // reloj desfasado marque como leído lo que no se vio.
            $table->unsignedBigInteger('ultimo_mensaje_id')->nullable();

            $table->timestamps();

            $table->unique(['conversacion_id', 'persona_id']);
        });

        Schema::create('foro_temas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            $table->string('titulo', 200);
            $table->text('cuerpo');

            // Del docente: fijar lo importante arriba y cerrar lo ya resuelto.
            $table->boolean('fijado')->default(false);
            $table->boolean('cerrado')->default(false);

            $table->unsignedInteger('respuestas')->default(0);
            $table->timestamp('ultima_respuesta_en')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['actividad_id', 'fijado', 'ultima_respuesta_en']);
        });

        Schema::create('foro_respuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('foro_tema_id')->constrained('foro_temas')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            /*
             * Un solo nivel de anidamiento: se responde al tema o a una
             * respuesta, y ahí para. Un árbol sin fondo se vuelve ilegible en
             * pantalla y nadie lo pidió.
             */
            $table->foreignId('responde_a_id')->nullable()->constrained('foro_respuestas')->cascadeOnDelete();

            $table->text('cuerpo');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['foro_tema_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foro_respuestas');
        Schema::dropIfExists('foro_temas');
        Schema::dropIfExists('conversacion_lecturas');
        Schema::dropIfExists('mensajes');
        Schema::dropIfExists('conversaciones');
    }
};
