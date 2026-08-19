<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El amarre entre la actividad y su rúbrica, y lo que resulta de aplicarla.
 *
 * ── `actividades.rubrica_id`: se apunta, no se copia ───────────────────────
 * Rompe con `CopiadorDeCurso`, que copia todo lo que se lleva un grupo, y es a
 * propósito. Copiar la rúbrica por actividad partiría el catálogo en cientos de
 * duplicados —una por grupo y ciclo— y entonces «las rúbricas de la plataforma»
 * dejaría de significar algo: nadie podría corregir una y ver el efecto.
 *
 * Lo que hacía peligroso apuntar —que editarla cambie una calificación ya
 * puesta— lo cierra el CONGELAMIENTO: en cuanto la rúbrica califica a alguien,
 * su estructura no se toca (se duplica). Así que apuntar es seguro por la misma
 * razón por la que copiar el examen era necesario: allá la plantilla sí se
 * podía editar mientras un grupo la contestaba.
 *
 * ── `entrega_rubrica`: un renglón por criterio evaluado ────────────────────
 * Es la evaluación en sí: qué nivel se le dio a cada criterio, cuántos puntos y
 * el comentario de ese renglón. La calificación de la entrega sigue viviendo en
 * `entregas.calificacion` —es la que promedia, y ninguna pantalla tiene que
 * saber de rúbricas para leerla—; esto explica de dónde salió.
 *
 * ── Por qué `puntos` se guarda pudiendo leerse del nivel ───────────────────
 * Porque la evaluación es un hecho fechado y el nivel es catálogo. Con el
 * congelamiento los dos números coinciden hoy, pero basta una restauración, una
 * carga masiva o un `activa = false` mal entendido para que el histórico
 * dependa de una fila que alguien puede mover. Un renglón que dice «le di 8» no
 * debería tener que preguntarle a nadie cuánto valía ese 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Los dos pasos por separado porque la mitad puede estar hecha: al
         * fallar la primera vez, la columna entró y la foránea no.
         */
        if (! Schema::hasColumn('actividades', 'rubrica_id')) {
            Schema::table('actividades', fn (Blueprint $table) => $table
                ->foreignId('rubrica_id')->nullable()->after('esquema_evaluacion_id'));
        }

        if (! $this->tieneLaForanea()) {
            /*
             * `nullOnDelete` y no cascada: si la rúbrica desapareciera de
             * verdad, la actividad se queda —con su calificación numérica de
             * siempre—. Borrar la actividad porque se borró su rúbrica sería
             * tirar las entregas de los alumnos.
             */
            Schema::table('actividades', fn (Blueprint $table) => $table
                ->foreign('rubrica_id')->references('id')->on('rubricas')->nullOnDelete());
        }

        Schema::create('entrega_rubrica', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entrega_id')->constrained('entregas')->cascadeOnDelete();
            $table->foreignId('criterio_id')->constrained('rubrica_criterios')->cascadeOnDelete();

            /*
             * Qué nivel se eligió. Nullable porque un criterio puede quedarse
             * sin evaluar mientras el docente va a medias, y porque NULL no es
             * cero: lo mismo que ya decide la captura de calificaciones.
             */
            $table->foreignId('nivel_id')->nullable()->constrained('rubrica_niveles')->nullOnDelete();

            $table->decimal('puntos', 6, 2)->default(0);
            $table->text('comentario')->nullable();

            $table->auditoria();

            // Un renglón por criterio y entrega: recalificar reemplaza, no
            // acumula. Empieza por `entrega_id`, así que sostiene su foránea.
            $table->unique(['entrega_id', 'criterio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrega_rubrica');

        if (Schema::hasColumn('actividades', 'rubrica_id')) {
            Schema::table('actividades', function (Blueprint $table) {
                $table->dropConstrainedForeignId('rubrica_id');
            });
        }
    }

    /**
     * Si la columna ya está sostenida por su foránea.
     *
     * Se pregunta a `information_schema` y no se supone por que exista la
     * columna: son dos pasos distintos y el primero puede haber quedado solo.
     */
    private function tieneLaForanea(): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'actividades')
            ->where('COLUMN_NAME', 'rubrica_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
