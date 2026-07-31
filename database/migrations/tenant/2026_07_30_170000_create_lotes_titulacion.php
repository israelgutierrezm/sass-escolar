<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de titulación (TENANT) — espejo del lote de certificación, pero para
 * títulos electrónicos que se envían al web service de la SEP.
 *
 * La diferencia clave es la ETAPA (pruebas/producción): se sella en el lote al
 * crearlo, tomándola de la etapa activa de `titulacion_ws_config`. Antes de
 * enviar el lote al WS se valida que su etapa siga coincidiendo con la activa,
 * para que un lote armado en producción no se mande por error al endpoint de
 * pruebas ni viceversa. Mientras el lote esté en borrador, la etapa se puede
 * editar (por si se creó en la etapa equivocada).
 *
 * Flujo: borrador → en_espera_firma → firmado (XML sellados) → enviado (al WS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_titulacion', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 40)->unique();
            $table->string('nombre', 160)->nullable();

            // Etapa con que se creó el lote: decide endpoint y credenciales del WS.
            $table->string('etapa', 12)->default('pruebas'); // pruebas | produccion

            $table->string('estado', 30)->default('borrador');

            // Quién firmó y con qué certificado (serie). Nulos hasta la firma.
            $table->foreignId('responsable_id')->nullable()->constrained('responsables')->nullOnDelete();
            $table->foreignId('certificado_responsable_id')->nullable()
                ->constrained('certificados_responsable')->nullOnDelete();

            $table->timestamp('cerrado_en')->nullable();
            $table->timestamp('firmado_en')->nullable();
            $table->timestamp('enviado_en')->nullable();

            $table->auditoria();

            $table->index('estado');
            $table->index('etapa');
        });

        Schema::create('titulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('lotes_titulacion')->cascadeOnDelete();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->restrictOnDelete();

            // pendiente (en el lote, sin firmar) | titulado | error.
            $table->string('estado', 20)->default('pendiente');

            $table->string('folio', 60)->nullable();
            // Serie del certificado del responsable con que se selló.
            $table->string('no_certificado', 100)->nullable();
            $table->longText('cadena_original')->nullable();
            $table->longText('sello')->nullable();
            // Ruta del XML de título firmado en el disco privado del tenant.
            $table->string('xml_path', 255)->nullable();
            // Foto de los datos académicos al momento de titular (no debe cambiar
            // si después se toca el kárdex).
            $table->json('datos_json')->nullable();
            $table->timestamp('fecha_titulacion')->nullable();
            $table->string('error_mensaje', 255)->nullable();

            // Respuesta del web service al enviar este título.
            $table->string('folio_proceso_ws', 100)->nullable();
            $table->string('estado_ws', 30)->nullable(); // enviado | aceptado | rechazado
            $table->text('respuesta_ws')->nullable();
            $table->timestamp('enviado_ws_en')->nullable();

            $table->auditoria();

            $table->unique(['lote_id', 'matricula_oferta_id']);
            $table->index('matricula_oferta_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulaciones');
        Schema::dropIfExists('lotes_titulacion');
    }
};
