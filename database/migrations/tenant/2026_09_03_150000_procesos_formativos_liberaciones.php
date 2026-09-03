<?php

declare(strict_types=1);

use App\Support\IndiceQueSostieneUnaFk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La liberación: el documento que dice que alguien terminó.
 *
 * ── Por qué es una TABLA y no una columna del expediente ──────────────────
 * Porque es un HECHO FECHADO con folio, y porque se puede CORREGIR sin
 * borrarse. Con una columna, enmendar una liberación mal emitida obligaría a
 * sobrescribirla —y con ella el folio que ya circula en un papel firmado—. Aquí
 * la corrección es otra fila que apunta a la anterior y las dos se conservan:
 * es exactamente el molde del **acta de corrección** y de la nota de crédito.
 *
 * ── El SNAPSHOT es la mitad del documento ─────────────────────────────────
 * Congela la regla aplicada y su versión, las horas, la organización, el
 * convenio, los informes y las evaluaciones al momento de liberar. Sin él, una
 * constancia emitida hace dos años se reconstruiría con los datos de hoy y
 * diría cosas que nadie firmó — la organización pudo cambiar de razón social, y
 * la regla, de horas exigidas.
 *
 * ── El FOLIO sale de un contador con incremento ATÓMICO ───────────────────
 * Nunca de un `MAX(folio)+1`, que colisiona bajo concurrencia. Y su tabla va
 * **sin `id` autoincremental**, que es la trampa documentada de
 * `contadores_matricula`: un INSERT sobre una tabla que lo tenga pisa
 * `LAST_INSERT_ID()` y el consecutivo entregado deja de ser el bueno.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->contador();
        $this->liberaciones();
    }

    private function contador(): void
    {
        if (Schema::hasTable('contadores_liberacion')) {
            return;
        }

        /*
         * SIN `id` AUTO_INCREMENT y sin auditoría, igual que
         * `contadores_matricula`. Un `deleted_at` aquí sería un arma: al ocultar
         * un contador se reiniciaría la numeración y se emitirían folios
         * duplicados sobre documentos que ya circulan.
         */
        Schema::create('contadores_liberacion', function (Blueprint $tabla) {
            $tabla->string('clave', 150)->primary();   // p. ej. «servicio_social:2026»
            $tabla->unsignedBigInteger('valor')->default(0);
            $tabla->timestamps();
        });
    }

    private function liberaciones(): void
    {
        if (! Schema::hasTable('liberaciones_proceso')) {
            Schema::create('liberaciones_proceso', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('expediente_id')->constrained('expedientes_proceso');

                $tabla->string('folio', 60);
                $tabla->date('liberado_en');
                $tabla->unsignedBigInteger('liberado_por')->nullable();

                /*
                 * Las horas ACREDITADAS se copian, no se leen de la bitácora al
                 * mirarlas: una jornada corregida después cambiaría lo que dice
                 * un documento ya emitido. Mismo criterio que el emisor
                 * congelado en la factura.
                 */
                $tabla->unsignedInteger('horas_acreditadas')->nullable();

                $tabla->json('snapshot');

                // La constancia, en el disco privado. Nullable porque la regla
                // puede no emitirla: liberar y entregar un papel son dos cosas.
                $tabla->string('constancia_ruta', 400)->nullable();

                /*
                 * La liberación que ésta corrige. La anterior NO se borra ni se
                 * edita: las dos se conservan y la vieja queda marcada como
                 * corregida, igual que un acta y su corrección.
                 */
                $tabla->foreignId('liberacion_corregida_id')->nullable()->constrained('liberaciones_proceso');
                $tabla->text('motivo_correccion')->nullable();

                /*
                 * CUÁNDO dejó de valer, no una bandera de que dejó.
                 *
                 * La fecha contesta además «¿cuánto tiempo circuló el folio
                 * equivocado?», que es lo primero que se pregunta al descubrir
                 * el error. Y es lo que mira el único: una liberación corregida
                 * deja de ocupar el lugar de su expediente.
                 */
                $tabla->timestamp('corregida_en')->nullable();

                $tabla->auditoria();

                $tabla->index('folio');
            });
        }

        /*
         * El ÚNICO va sobre una columna generada, no sobre el folio pelado.
         *
         * Dos reglas a la vez: un expediente tiene UNA liberación vigente —la
         * corregida deja de contar— y un folio no se repite entre las vivas. Con
         * un único sobre `expediente_id` a secas, emitir la corrección sería
         * imposible; con uno que incluyera `deleted_at`, MySQL da dos NULL por
         * distintos y pasarían dos vivas.
         *
         * Comprobar antes de actuar es por PIEZA y no por bloque: la lección del
         * CHECK de movilidad, que se quedó sin crear PARA SIEMPRE por vivir
         * dentro de un `if (! hasTable)` que un reintento se saltó.
         */
        if (! Schema::hasColumn('liberaciones_proceso', 'expediente_si_vigente')) {
            Schema::getConnection()->statement(
                'ALTER TABLE liberaciones_proceso ADD COLUMN expediente_si_vigente BIGINT UNSIGNED '
                .'AS (CASE WHEN deleted_at IS NULL AND corregida_en IS NULL THEN expediente_id END) STORED'
            );
        }

        if (! IndiceQueSostieneUnaFk::existe('liberaciones_proceso', 'liberacion_vigente_unica')) {
            Schema::table('liberaciones_proceso', function (Blueprint $tabla) {
                $tabla->unique('expediente_si_vigente', 'liberacion_vigente_unica');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('liberaciones_proceso');
        Schema::dropIfExists('contadores_liberacion');
    }
};
