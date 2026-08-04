<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encuestas de evaluación: preguntar a la escuela y poder decidir con lo que
 * conteste.
 *
 * ── El instrumento y su aplicación son cosas distintas ─────────────────────
 * `encuestas` es el CUESTIONARIO —las preguntas—; `aplicaciones_encuesta` es
 * cada vez que se pone en marcha, con sus fechas, sus destinatarios y sus
 * reglas. Separarlos es lo que permite tener una plantilla de evaluación
 * docente y lanzarla cada semestre sin volver a capturarla.
 *
 * Y por eso aplicar desde una plantilla COPIA las preguntas en vez de
 * apuntarlas: si la plantilla se edita en marzo, la encuesta que 300 alumnos
 * contestaron en febrero no puede cambiar debajo. Los resultados quedarían
 * atribuidos a preguntas que nadie vio.
 *
 * ── Un sujeto por docente evaluado ─────────────────────────────────────────
 * En la evaluación docente el alumno no contesta «una» encuesta: contesta una
 * POR CADA docente que le da clase. `aplicacion_sujetos` es cada uno de esos
 * destinatarios de la evaluación —el docente en una materia concreta—, y se
 * generan solos a partir de los filtros que elija la escuela. Sin ellos habría
 * que capturar a mano cuarenta encuestas idénticas por ciclo.
 *
 * ── Quién contestó y qué contestó, separados ───────────────────────────────
 * `encuesta_participaciones` guarda QUIÉN respondió; `encuesta_respuestas`,
 * QUÉ se respondió. Están en tablas distintas y sin llave entre ellas a
 * propósito: es lo que hace que una encuesta anónima lo sea de verdad y que, aun
 * así, se pueda saber a quién le falta contestar y exigírselo. Con un
 * `persona_id` en la respuesta, el anonimato dependería de que nadie mire la
 * tabla, que no es anonimato: es una promesa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── El instrumento ──────────────────────────────────────────────────

        Schema::create('encuestas', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 180);
            $table->text('descripcion')->nullable();

            /*
             * Una plantilla no se contesta: es el molde del que salen las
             * aplicaciones. Se marca aquí y no en otra tabla porque, salvo por
             * eso, es exactamente lo mismo —las mismas preguntas, el mismo
             * editor— y duplicar la estructura obligaría a mantener dos.
             */
            $table->boolean('es_plantilla')->default(false);
            $table->boolean('activa')->default(true);

            $table->auditoria();
        });

        Schema::create('encuesta_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encuesta_id')->constrained('encuestas')->cascadeOnDelete();

            $table->text('texto');
            // La aclaración que evita que cada quien entienda otra cosa.
            $table->string('ayuda', 300)->nullable();

            // Ver App\Enums\TipoPregunta.
            $table->string('tipo', 20);
            $table->boolean('requerida')->default(true);

            /*
             * Lo propio de cada tipo: los extremos de una escala, el mínimo y
             * el máximo de un número. Va en JSON porque cada tipo necesita
             * cosas distintas y una columna por cada una dejaría la tabla llena
             * de nulos que sólo sirven a un tipo.
             */
            $table->json('config')->nullable();

            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['encuesta_id', 'orden']);
        });

        Schema::create('encuesta_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pregunta_id')->constrained('encuesta_preguntas')->cascadeOnDelete();

            $table->string('texto', 200);

            /*
             * El peso numérico de la opción, si lo tiene.
             *
             * «Siempre = 4, casi siempre = 3…» convierte una pregunta de
             * opciones en algo promediable. Nulo cuando la opción no ordena
             * nada —«¿qué servicios usas?»— y entonces sólo se cuenta.
             */
            $table->decimal('valor', 6, 2)->nullable();

            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['pregunta_id', 'orden']);
        });

        // ── La aplicación ───────────────────────────────────────────────────

        Schema::create('aplicaciones_encuesta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encuesta_id')->constrained('encuestas')->restrictOnDelete();

            $table->string('titulo', 180);
            $table->text('instrucciones')->nullable();

            // docente | general. La primera se contesta una vez por cada
            // docente evaluado; la segunda, una sola vez.
            $table->string('tipo', 20)->default('general');

            $table->dateTime('abre_en')->nullable();
            $table->dateTime('cierra_en')->nullable();

            /*
             * Obligatoria: se interpone hasta que se conteste, como el aviso
             * crítico. Es la única forma de conseguir una participación que
             * sirva estadísticamente —una encuesta voluntaria la contesta quien
             * tiene algo que reclamar, y eso sesga el resultado—, y por lo mismo
             * hay que usarla con cuidado.
             */
            $table->boolean('obligatoria')->default(false);

            /*
             * Anónima: no se guarda quién dijo qué. Para evaluar a un docente es
             * casi obligado —un alumno que teme la calificación no contesta lo
             * que piensa—, y por eso viene encendida por omisión.
             */
            $table->boolean('anonima')->default(true);

            // borrador | publicada | cerrada
            $table->string('estado', 20)->default('borrador');

            $table->auditoria();

            $table->index(['estado', 'abre_en', 'cierra_en']);
        });

        // Mismos criterios que los avisos y el calendario: DestinoEvento.
        Schema::create('aplicacion_destinos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_encuesta')->cascadeOnDelete();

            $table->string('tipo', 20);
            $table->unsignedBigInteger('destino_id')->nullable();

            $table->timestamps();

            $table->index(['tipo', 'destino_id']);
            $table->unique(['aplicacion_id', 'tipo', 'destino_id']);
        });

        /**
         * A quién se evalúa. Vacío en una encuesta general: no se evalúa a
         * nadie, se pregunta por un tema.
         */
        Schema::create('aplicacion_sujetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_encuesta')->cascadeOnDelete();

            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('asignatura_grupo_id')->nullable()->constrained('asignatura_grupo')->cascadeOnDelete();

            // titular | adjunto: la escuela decide si evalúa a los dos.
            $table->string('papel', 20)->nullable();

            $table->timestamps();

            $table->unique(['aplicacion_id', 'persona_id', 'asignatura_grupo_id'], 'sujeto_unico');
        });

        // ── Lo contestado ───────────────────────────────────────────────────

        Schema::create('encuesta_participaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_encuesta')->cascadeOnDelete();
            $table->foreignId('sujeto_id')->nullable()->constrained('aplicacion_sujetos')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            $table->timestamp('respondido_en');
            $table->timestamps();

            // Una vez por persona y por sujeto. Con sujeto nulo —encuesta
            // general— eso es una vez y ya.
            $table->unique(['aplicacion_id', 'sujeto_id', 'persona_id'], 'participacion_unica');
            $table->index(['persona_id', 'aplicacion_id']);
        });

        Schema::create('encuesta_respuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_encuesta')->cascadeOnDelete();
            $table->foreignId('sujeto_id')->nullable()->constrained('aplicacion_sujetos')->cascadeOnDelete();

            /*
             * SIN persona_id. Ver el encabezado: es lo que hace que el anonimato
             * no dependa de la buena voluntad de quien consulta la base.
             *
             * Los datos de contexto que sí se guardan —el rol, el campus— son
             * para poder segmentar los resultados; se eligen de forma que no
             * identifiquen a nadie por sí solos.
             */
            $table->foreignId('rol_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campus')->nullOnDelete();

            $table->timestamp('enviada_en');
            $table->timestamps();

            $table->index(['aplicacion_id', 'sujeto_id']);
        });

        Schema::create('encuesta_respuesta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('respuesta_id')->constrained('encuesta_respuestas')->cascadeOnDelete();
            $table->foreignId('pregunta_id')->constrained('encuesta_preguntas')->cascadeOnDelete();

            // Sólo uno según el tipo de pregunta. En opción múltiple hay un
            // renglón por cada opción marcada.
            $table->foreignId('opcion_id')->nullable()->constrained('encuesta_opciones')->cascadeOnDelete();
            $table->decimal('numero', 10, 2)->nullable();
            $table->text('texto')->nullable();

            $table->timestamps();

            // El índice con el que se calculan todos los promedios y conteos.
            $table->index(['pregunta_id', 'opcion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encuesta_respuesta_items');
        Schema::dropIfExists('encuesta_respuestas');
        Schema::dropIfExists('encuesta_participaciones');
        Schema::dropIfExists('aplicacion_sujetos');
        Schema::dropIfExists('aplicacion_destinos');
        Schema::dropIfExists('aplicaciones_encuesta');
        Schema::dropIfExists('encuesta_opciones');
        Schema::dropIfExists('encuesta_preguntas');
        Schema::dropIfExists('encuestas');
    }
};
