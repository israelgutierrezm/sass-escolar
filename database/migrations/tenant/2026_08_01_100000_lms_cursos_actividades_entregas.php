<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cimientos del LMS: el contenido de una materia y lo que el alumno entrega.
 *
 * ── UNA sola forma para plantilla e instancia ──────────────────────────────
 * `cursos` sirve para las dos cosas y se distinguen por a qué cuelgan:
 *
 *   - `plan_materia_id` lleno  → PLANTILLA. El armado en línea que el
 *     administrador prepara una vez para la materia del plan.
 *   - `asignatura_grupo_id` lleno → INSTANCIA. Lo que de verdad cursa un grupo
 *     en un ciclo.
 *
 * Se resolvió así y no con dos árboles de tablas porque duplicar `actividades`
 * —y mañana `reactivos`, `entregas`— en versión-plantilla y versión-real
 * significa mantener dos veces cada regla. Aquí copiar una plantilla es clonar
 * filas cambiando `curso_id`, y `plantilla_origen_id` deja el rastro de de
 * dónde salió.
 *
 * La decisión de COPIAR (y no leer la plantilla por referencia) es del usuario:
 * el docente ajusta su grupo sin afectar a los demás, y un grupo ya cursado
 * conserva lo que realmente se aplicó, no lo que la plantilla dice hoy.
 *
 * ── La ponderación no se reinventa ─────────────────────────────────────────
 * Una actividad se amarra a un `esquema_evaluacion_id`, que ya existe y ya
 * define los componentes ponderados por parcial que configura el administrador.
 * Sin ese amarre la actividad es formativa: se entrega y se retroalimenta, pero
 * no promedia. Es exactamente la distinción que pidió el usuario entre lo que
 * el docente puede cargar libremente y lo que la escuela pondera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cursos', function (Blueprint $table) {
            $table->id();

            // Exactamente uno de los dos va lleno; lo verifica el modelo.
            $table->foreignId('plan_materia_id')->nullable()->constrained('plan_materias')->nullOnDelete();
            $table->foreignId('asignatura_grupo_id')->nullable()->constrained('asignatura_grupo')->cascadeOnDelete();

            // De qué plantilla se copió esta instancia. Informativo: al copiar,
            // el contenido ya es suyo y la plantilla puede cambiar sin arrastrarlo.
            $table->foreignId('plantilla_origen_id')->nullable()->constrained('cursos')->nullOnDelete();

            $table->string('titulo')->nullable();
            $table->text('presentacion')->nullable();

            /*
             * Qué puede hacer el docente en ESTA instancia. En un curso en línea
             * la escuela puede querer que solo califique; el interruptor lo dice
             * en el dato y no en una regla escondida en el código.
             */
            $table->boolean('docente_puede_agregar')->default(true);
            $table->boolean('docente_puede_ponderar')->default(true);

            $table->boolean('publicado')->default(false);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // Una plantilla por materia del plan y una instancia por materia
            // impartida: si hicieran falta dos, sería otra cosa (una unidad).
            $table->unique('plan_materia_id');
            $table->unique('asignatura_grupo_id');
        });

        Schema::create('actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->constrained('cursos')->cascadeOnDelete();

            // lectura | actividad | foro | examen
            $table->string('tipo', 20);
            $table->string('titulo');
            $table->text('instrucciones')->nullable();

            /*
             * A qué componente ponderado cuelga. NULL = formativa: se entrega y
             * se retroalimenta pero no promedia. Es lo que le queda al docente
             * cuando la escuela no le deja ponderar.
             */
            $table->foreignId('esquema_evaluacion_id')->nullable()->constrained('esquema_evaluacion')->nullOnDelete();

            // Sobre cuánto se califica esta actividad (su escala propia). La
            // conversión al componente la hace el calculador, no el docente.
            $table->decimal('puntos', 6, 2)->default(10);

            $table->dateTime('abre_en')->nullable();
            $table->dateTime('cierra_en')->nullable();
            // Entregar tarde: se permite o no, y si se permite queda marcado.
            $table->boolean('permite_tarde')->default(false);

            $table->unsignedInteger('orden')->default(0);
            $table->boolean('publicada')->default(false);

            // Lo propio de cada tipo (html/scorm de una lectura, modo de entrega
            // de una actividad, reglas de un examen). Se guarda como JSON porque
            // cada tipo pide campos distintos y columnas nulas por doquier
            // esconden qué aplica a qué.
            $table->json('config')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['curso_id', 'orden']);
        });

        Schema::create('entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            // Cuelga de la INSCRIPCIÓN, no de la persona: es lo que ata al
            // alumno con esa materia en ese ciclo, y es lo que ya usan las
            // calificaciones y la asistencia.
            $table->foreignId('inscripcion_id')->constrained('inscripcion')->cascadeOnDelete();

            // pendiente | entregada | calificada
            $table->string('estado', 20)->default('pendiente');
            $table->text('contenido')->nullable();
            $table->dateTime('entregada_en')->nullable();
            // Se marca al entregar, comparando contra `cierra_en`: después la
            // fecha puede moverse y el dato histórico se perdería.
            $table->boolean('tarde')->default(false);

            $table->decimal('calificacion', 5, 2)->nullable();
            $table->text('retroalimentacion')->nullable();
            $table->unsignedBigInteger('calificada_por')->nullable();
            $table->dateTime('calificada_en')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // Un renglón por alumno y actividad. Volver a entregar REEMPLAZA el
            // contenido; no crea una fila nueva. Mismo criterio que en
            // `inscripcion`, donde insertar de nuevo reventaba contra el unique.
            $table->unique(['actividad_id', 'inscripcion_id']);
        });

        Schema::create('entrega_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();
            $table->string('ruta');
            $table->string('nombre');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('mime', 100)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
        });

        /*
         * De dónde salió la calificación de un componente.
         *
         * El usuario decidió que la calificación de una actividad ENTRE SOLA al
         * componente: el calculador escribe con fuente `calculado`. Pero el
         * docente tiene que poder fijar un número a mano —un ajuste, un caso
         * especial— sin que el siguiente recálculo se lo pise. `manual` es esa
         * marca: el calculador la respeta y no la toca.
         */
        Schema::table('calificaciones_componente', function (Blueprint $table) {
            $table->string('fuente', 12)->default('manual')->after('calificacion');
        });
    }

    public function down(): void
    {
        Schema::table('calificaciones_componente', fn (Blueprint $t) => $t->dropColumn('fuente'));
        Schema::dropIfExists('entrega_archivos');
        Schema::dropIfExists('entregas');
        Schema::dropIfExists('actividades');
        Schema::dropIfExists('cursos');
    }
};
