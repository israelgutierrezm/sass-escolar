<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exámenes: banco de reactivos, armado del examen, intentos y respuestas.
 *
 * ── El banco es del CURSO, no del examen ───────────────────────────────────
 * `reactivos` cuelga del curso y `examen_reactivo` dice cuáles usa cada examen.
 * Colgarlos del examen habría sido más corto de escribir y habría cerrado la
 * puerta a lo único que hace útil un banco: reutilizar la misma pregunta en el
 * parcial y en el extraordinario, y sortear diez de treinta para que dos
 * alumnos no vean el mismo examen. Con el banco eso es una consulta; sin él,
 * volver a capturar.
 *
 * Y como el banco cuelga del curso, la copia de una plantilla en línea se lo
 * lleva junto con sus actividades, sin un caso especial.
 *
 * ── Intento y entrega ──────────────────────────────────────────────────────
 * `entregas` ya es «un renglón por alumno y actividad» y sigue siendo el lugar
 * de la calificación final. Un examen puede permitir VARIOS intentos, así que
 * cada uno es su propia fila en `intentos` y la entrega se queda con el que
 * mande según la regla configurada. Meter el intento en la entrega habría hecho
 * imposible conservar el historial de lo que el alumno contestó cada vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examenes', function (Blueprint $table) {
            $table->id();
            // Un examen ES una actividad de tipo `examen`: hereda de ella las
            // fechas, la ponderación y el amarre al componente. Aquí solo van
            // las reglas propias de examinar.
            $table->foreignId('actividad_id')->unique()->constrained('actividades')->cascadeOnDelete();

            $table->unsignedSmallInteger('intentos_permitidos')->default(1);
            $table->unsignedSmallInteger('minutos_limite')->nullable();

            /*
             * Cuántos reactivos se le presentan al alumno. Si es menor que los
             * del examen, se sortean: es lo que evita que el de junto copie.
             * Null = todos, en el orden definido.
             */
            $table->unsignedSmallInteger('reactivos_a_presentar')->nullable();
            $table->boolean('barajar_reactivos')->default(false);
            $table->boolean('barajar_opciones')->default(false);

            // Con qué se queda la entrega cuando hay varios intentos.
            $table->string('intento_que_cuenta', 12)->default('mejor'); // mejor | ultimo | primero

            // Cuándo ve el alumno su resultado y la retroalimentación.
            $table->string('mostrar_resultado', 16)->default('al_cerrar'); // nunca | al_entregar | al_cerrar

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        Schema::create('reactivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->constrained('cursos')->cascadeOnDelete();

            $table->string('tipo', 24);
            $table->text('enunciado');
            // Imagen del reactivo (obligatoria en hotspot, opcional en el resto).
            $table->string('imagen')->nullable();

            $table->decimal('puntos', 6, 2)->default(1);
            // Lo que se le dice al alumno al revelar el resultado, acierte o no.
            $table->text('retroalimentacion')->nullable();

            /*
             * La respuesta correcta, en la forma que pida el tipo: lista de
             * aceptadas en respuesta corta, valor y tolerancia en numérica, los
             * huecos en completar, la zona en hotspot. Es JSON porque cada tipo
             * define algo distinto y doce columnas casi siempre nulas no dirían
             * cuál aplica a cuál.
             */
            $table->json('respuesta')->nullable();

            // Para reencontrar reactivos en un banco grande.
            $table->string('tema')->nullable();
            $table->string('dificultad', 10)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['curso_id', 'tema']);
        });

        Schema::create('reactivo_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reactivo_id')->constrained('reactivos')->cascadeOnDelete();

            $table->text('texto');
            $table->boolean('correcta')->default(false);

            /*
             * Con qué empareja, en los tipos que emparejan: la columna derecha
             * de una relación, o la categoría en un clasificar. En los demás va
             * nulo.
             */
            $table->string('pareja')->nullable();
            $table->unsignedInteger('orden')->default(0);

            $table->timestamps();

            $table->index(['reactivo_id', 'orden']);
        });

        // Qué reactivos arma cada examen, con su peso dentro de ÉL: la misma
        // pregunta puede valer 1 en un parcial y 3 en el extraordinario.
        Schema::create('examen_reactivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id')->constrained('examenes')->cascadeOnDelete();
            $table->foreignId('reactivo_id')->constrained('reactivos')->cascadeOnDelete();
            $table->decimal('puntos', 6, 2)->nullable();
            $table->unsignedInteger('orden')->default(0);

            $table->unique(['examen_id', 'reactivo_id']);
        });

        Schema::create('intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id')->constrained('examenes')->cascadeOnDelete();
            $table->foreignId('inscripcion_id')->constrained('inscripcion')->cascadeOnDelete();
            // La entrega con la que se sincroniza la calificación final.
            $table->foreignId('entrega_id')->nullable()->constrained('entregas')->nullOnDelete();

            $table->unsignedSmallInteger('numero')->default(1);
            $table->dateTime('iniciado_en');
            $table->dateTime('entregado_en')->nullable();
            // Se calcula al iniciar, desde `minutos_limite`: mover el límite
            // después no debe alargar un examen ya en curso.
            $table->dateTime('expira_en')->nullable();

            $table->decimal('puntos_obtenidos', 8, 2)->nullable();
            $table->decimal('puntos_posibles', 8, 2)->nullable();
            // Queda pendiente mientras tenga reactivos que el docente deba leer.
            $table->boolean('requiere_revision')->default(false);

            /*
             * El ORDEN en que se le presentaron los reactivos a ESTE alumno.
             * Sin esto, un examen barajado sería irreproducible: al revisar una
             * inconformidad no habría forma de saber qué vio.
             */
            $table->json('orden_reactivos')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['examen_id', 'inscripcion_id', 'numero']);
        });

        Schema::create('respuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intento_id')->constrained('intentos')->cascadeOnDelete();
            $table->foreignId('reactivo_id')->constrained('reactivos')->cascadeOnDelete();

            // Lo que contestó, en la forma del tipo (ids de opción, texto,
            // número, pares, orden…).
            $table->json('valor')->nullable();

            $table->decimal('puntos', 6, 2)->nullable();
            $table->boolean('correcta')->nullable();
            // De la máquina o del docente: distingue lo revisado a mano.
            $table->string('calificada_por_maquina', 3)->default('si');
            $table->text('comentario')->nullable();

            $table->timestamps();

            $table->unique(['intento_id', 'reactivo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respuestas');
        Schema::dropIfExists('intentos');
        Schema::dropIfExists('examen_reactivo');
        Schema::dropIfExists('reactivo_opciones');
        Schema::dropIfExists('reactivos');
        Schema::dropIfExists('examenes');
    }
};
