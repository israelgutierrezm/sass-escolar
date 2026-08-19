<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rúbricas de evaluación: con qué se califica un trabajo que no tiene respuesta
 * correcta.
 *
 * ── El problema que resuelven ──────────────────────────────────────────────
 * Un examen de opción múltiple se califica solo. Un ensayo, una maqueta o una
 * exposición no: hoy el docente escribe un número en una casilla y el alumno
 * recibe un 7 sin saber qué le faltó. La rúbrica parte ese número en criterios
 * con niveles descritos, así que el 7 deja de ser una opinión y pasa a ser una
 * suma de decisiones que se pueden mirar una por una.
 *
 * ── DOS ámbitos, no dos tablas ─────────────────────────────────────────────
 * El cliente pidió rúbricas «de la plataforma» —las que la escuela arma para
 * todos— y «del docente» —las que cada quien se hace—. Son la misma cosa con
 * distinto dueño, así que van en una tabla con `ambito`:
 *
 *   - `plataforma`: `persona_id` en NULL. Las ve y las usa toda la escuela; las
 *     edita quien tenga `gestionar-rubricas`.
 *   - `docente`: `persona_id` lleno. Sólo su dueño las ve y las usa.
 *
 * Dos tablas habrían obligado a duplicar criterios y niveles, y entonces cada
 * regla —el congelamiento, el cálculo, el copiado— existiría dos veces.
 *
 * ── El máximo de un criterio NO se guarda ──────────────────────────────────
 * Se deriva del nivel más alto. Una columna `puntos` en el criterio podría
 * contradecir a sus niveles («vale 10» con un nivel máximo de 8) y no hay forma
 * de saber cuál de las dos manda. Aquí los puntos viven en UN solo sitio: el
 * nivel. Lo mismo con el total de la rúbrica, que es la suma de los máximos.
 *
 * ── Se congela al primer uso, no se versiona ───────────────────────────────
 * En cuanto una rúbrica califica a alguien, su estructura deja de editarse: si
 * se le pudiera quitar un criterio, las evaluaciones hechas dirían un total que
 * ya no cuadra con la suma de sus partes. Para cambiarla se DUPLICA. Es la misma
 * decisión que ya toman `formularios` (se congela con la primera respuesta) y
 * `esquema_evaluacion` (no se edita con calificaciones capturadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubricas', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 180);
            $table->text('descripcion')->nullable();

            // plataforma | docente
            $table->string('ambito', 12)->default('plataforma');

            /*
             * El dueño, sólo en las del docente.
             *
             * Sin FK a `personas` no: aquí sí es del tenant, así que la foránea
             * existe. `restrictOnDelete` no hace falta —las personas no se
             * borran de verdad— y `nullOnDelete` convertiría la rúbrica de
             * alguien en una de plataforma sin que nadie lo decidiera.
             */
            $table->foreignId('persona_id')->nullable()->constrained('personas')->cascadeOnDelete();

            /*
             * Apagada = no se ofrece al amarrar una actividad nueva.
             *
             * No se borra ni deja de calificar donde ya está puesta: retirar una
             * rúbrica del catálogo no puede cambiar la nota de nadie.
             */
            $table->boolean('activa')->default(true);

            $table->auditoria();

            // Por dónde se listan: las mías, y las de la escuela.
            $table->index(['ambito', 'persona_id']);
        });

        Schema::create('rubrica_criterios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubrica_id')->constrained('rubricas')->cascadeOnDelete();

            $table->string('titulo', 180);
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('orden')->default(0);

            $table->auditoria();

            // Empieza por `rubrica_id`, así que es el índice que sostiene su
            // foránea: no hace falta otro.
            $table->index(['rubrica_id', 'orden']);
        });

        Schema::create('rubrica_niveles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criterio_id')->constrained('rubrica_criterios')->cascadeOnDelete();

            // «Excelente», «Suficiente»… El nombre corto que se lee en el chip.
            $table->string('titulo', 120);
            // Qué tiene que haber hecho para merecerlo. Es lo que convierte la
            // rúbrica en algo que el alumno puede leer ANTES de entregar.
            $table->text('descripcion')->nullable();

            $table->decimal('puntos', 6, 2)->default(0);
            $table->unsignedInteger('orden')->default(0);

            $table->auditoria();

            $table->index(['criterio_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubrica_niveles');
        Schema::dropIfExists('rubrica_criterios');
        Schema::dropIfExists('rubricas');
    }
};
