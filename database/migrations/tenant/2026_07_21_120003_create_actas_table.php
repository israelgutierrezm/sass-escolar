<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * actas (TENANT) — el acta de calificaciones como entidad, no solo un folio.
 *
 * La spec solo previó `historial.acta_folio` (varchar). Se eleva a tabla
 * porque el acta es un documento con valor legal: hay que poder reimprimirla
 * tal como se firmó, saber QUIÉN la cerró y CUÁNDO, y emitir una corrección
 * sin borrar la original. Un folio suelto en el kárdex no sostiene nada de
 * eso. `historial.acta_folio` se conserva (dato de la spec, es lo que se
 * imprime) y se acompaña de `historial.acta_id` para la integridad real.
 *
 * `acta_origen_id` apunta al acta que esta corrige. Una calificación nunca se
 * edita en el kárdex: se emite un acta de corrección que asienta filas nuevas,
 * y la cadena queda trazable. Es lo que ya insinuaba el catálogo
 * `observaciones_historial` con "Corrección de calificación".
 *
 * `situacion` va como varchar con constantes en el modelo —no como catálogo
 * TENANT-CONFIG— porque sus tres valores son parte de la máquina de estados
 * del código, no algo que una escuela deba poder renombrar o ampliar. Mismo
 * criterio que `inscripcion.tipo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo');
            $table->foreignId('tipo_evaluacion_id')->constrained('tipos_evaluacion');
            $table->string('folio', 50)->unique();
            $table->string('situacion', 20)->default('abierta'); // abierta / cerrada / cancelada

            // El titular que firma. Se guarda la PERSONA, no el usuario: quien
            // firma el acta es el docente, y su cuenta puede desaparecer.
            $table->foreignId('cerrada_por')->nullable()->constrained('personas');
            $table->timestamp('cerrada_en')->nullable();

            $table->foreignId('acta_origen_id')->nullable()->constrained('actas');
            $table->text('observaciones')->nullable();

            $table->auditoria();

            $table->index(['asignatura_grupo_id', 'tipo_evaluacion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actas');
    }
};
