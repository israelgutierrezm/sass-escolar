<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los documentos que la escuela le pide al TUTOR FAMILIAR, no a su hijo.
 *
 * ── El ámbito llevaba tiempo sin quien lo consumiera ───────────────────────
 * `DocumentoRequerido::AMBITO_TUTOR` está en el catálogo desde el principio y
 * la escuela demo YA lo usa: «Identificación oficial» está marcada como
 * obligatoria para tutores. Sólo que no había dónde entregarla — el portal de
 * la familia únicamente muestra a los hijos—, así que ese requisito vivía
 * marcado en el sistema y se cobraba en ventanilla. Es el mismo hueco que tenía
 * el ámbito `alumno` antes de «Mi expediente».
 *
 * ── Son los papeles DEL TUTOR ──────────────────────────────────────────────
 * La identificación del padre, su comprobante de domicilio, una carta
 * responsiva. No los del alumno: ésos ya tienen su tabla y su pantalla, y quien
 * los sube es el alumno. Por eso cuelga de la persona del tutor y no del
 * vínculo con un hijo concreto: una madre con tres hijos en la escuela entrega
 * su identificación UNA vez, no tres.
 *
 * ── Misma forma que las otras dos ──────────────────────────────────────────
 * `documentos_alumno` y `documentos_docente` ya son esto mismo colgando de la
 * persona. Se repite la forma a propósito en vez de inventar una tabla común:
 * cada portal lista lo suyo sin filtrar por ámbito, y una sola tabla haría que
 * los papeles del tutor asomaran en el expediente del alumno de quien es las
 * dos cosas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_tutor', function (Blueprint $table) {
            $table->id();

            /*
             * Contra `personas` y no contra la tabla del vínculo: alguien deja
             * de ser tutor de un alumno que egresa y sigue siendo tutor de otro,
             * y sus papeles no tienen por qué irse con el primer vínculo.
             */
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('documento_id')->constrained('documentos_requeridos');
            $table->string('descripcion', 100)->nullable();
            $table->string('url', 500);
            $table->foreignId('estado_documento_id')->constrained('estados_documento');

            // Algunos vencen: la identificación oficial, el comprobante de
            // domicilio.
            $table->date('vigencia')->nullable();

            // Por qué se rechazó. Sin esto, «rechazado» obliga a adivinar qué
            // corregir antes de volver a subirlo.
            $table->string('observaciones', 255)->nullable();

            $table->auditoria();

            // Un renglón por tipo: volver a subir el mismo reemplaza, no acumula
            // copias de la misma identificación.
            $table->unique(['persona_id', 'documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_tutor');
    }
};
