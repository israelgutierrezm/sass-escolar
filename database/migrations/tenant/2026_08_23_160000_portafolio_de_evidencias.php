<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portafolio de evidencias (TENANT) — el cuarto tipo de actividad del LMS.
 *
 * La spec lo lista desde el principio entre los tipos de actividad y viene del
 * legacy; se quedó fuera al construir el módulo 8 y **nadie lo anotó**, así que
 * el módulo se declaró completo sin él. Salió al auditar
 * `plan-migraciones.md` contra la base, el 2026-08-23.
 *
 * ── En qué se diferencia de una tarea con varios archivos ─────────────────
 * Una tarea se entrega DE UNA VEZ: sus `entrega_archivos` son adjuntos sin
 * nombre propio ni fecha propia, y sirven todos a lo mismo. Un portafolio se
 * ACUMULA a lo largo del curso, y cada pieza tiene su título, su descripción y
 * su momento —«práctica 3, hecha en octubre, esto es lo que aprendí»—. Esa
 * descripción por pieza ES el portafolio: sin ella sería una carpeta de
 * archivos, que es justo lo que ya existía y no hacía falta duplicar.
 *
 * ── Y por eso cuelga de `entregas`, no de (inscripción, actividad) ────────
 * La spec pedía `portafolio_evidencias.inscripcion_id` + `actividad_id`, que es
 * exactamente la pareja que `entregas` ya identifica. Colgarlo aparte crearía
 * DOS filas diciendo «el trabajo de esta alumna en esta actividad», y al
 * calificar habría que elegir a cuál creerle.
 *
 * Colgando de la entrega se hereda todo lo que ya funciona y no hay que
 * reescribir: la calificación, la retroalimentación, la rúbrica
 * (`entrega_rubrica`), el «entregada tarde», el panel de calificación del
 * docente y la vista del alumno en el aula. El portafolio aporta las PIEZAS; la
 * entrega sigue siendo el trabajo.
 *
 * ── Dos tablas y no una ───────────────────────────────────────────────────
 * Una evidencia puede necesitar varios archivos —la foto del montaje, el video
 * del ensayo y el PDF del reporte son UNA evidencia— y a la vez puede no
 * necesitar ninguno: una reflexión escrita es evidencia legítima. Con una sola
 * tabla habría que repetir el título y la descripción en cada archivo, y
 * entonces corregir una errata sería corregirla tres veces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portafolio_evidencias')) {
            Schema::create('portafolio_evidencias', function (Blueprint $t) {
                $t->id();

                $t->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();

                $t->string('titulo', 180);

                /*
                 * La descripción es lo que convierte un archivo en evidencia:
                 * qué es, por qué está aquí y qué demuestra. Es opcional en la
                 * base y la exige —o no— la actividad, porque hay portafolios
                 * de bitácora donde el título basta.
                 */
                $t->text('descripcion')->nullable();

                /*
                 * Cuándo ocurrió lo que la evidencia documenta, que NO es cuándo
                 * se subió. Una práctica de laboratorio de octubre se captura en
                 * diciembre al armar el portafolio, y ordenarla por `created_at`
                 * contaría la historia al revés.
                 */
                $t->date('fecha_evidencia')->nullable();

                // Lo pone el alumno arrastrando: el orden de un portafolio es
                // una decisión suya, es cómo cuenta lo que aprendió.
                $t->unsignedSmallInteger('orden')->default(0);

                $t->auditoria();

                $t->index(['entrega_id', 'orden']);
            });
        }

        if (! Schema::hasTable('portafolio_archivos')) {
            Schema::create('portafolio_archivos', function (Blueprint $t) {
                $t->id();

                $t->foreignId('evidencia_id')
                    ->constrained('portafolio_evidencias')->cascadeOnDelete();

                // Misma forma que `entrega_archivos`: es el mismo hecho —un
                // archivo en el disco privado— y darle otra forma obligaría a
                // dos maneras de pedir el peso legible.
                $t->string('ruta', 500);
                $t->string('nombre', 255);
                $t->unsignedBigInteger('bytes')->nullable();
                $t->string('mime', 120)->nullable();

                $t->auditoria();
            });
        }
    }

    public function down(): void
    {
        // Los archivos primero: la foránea los sostiene.
        Schema::dropIfExists('portafolio_archivos');
        Schema::dropIfExists('portafolio_evidencias');
    }
};
