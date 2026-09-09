<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La solicitud de factura que un alumno o su familia pide desde su portal.
 *
 * ── Solicitar NO es emitir ─────────────────────────────────────────────────
 * Misma distinción que hace `comprobantes_pago` con el pago: aquí no hay CFDI
 * hasta que alguien de la escuela lo emite. Emitir un comprobante fiscal a
 * nombre de la escuela es un acto administrativo —lo dice R06.01— y no se le
 * concede al alumno. Lo que el alumno hace es DEJAR CONSTANCIA de que quiere su
 * factura de estas operaciones, con los datos con los que la quiere; el `factura`
 * nace cuando una persona con permiso la emite, reusando el motor de siempre.
 *
 * ── Por qué guarda los pagos y el receptor ─────────────────────────────────
 * Los `pago_ids` son las operaciones que se piden facturar —el control de
 * duplicados protege esa cobertura, no «un pago = una factura»—. El receptor se
 * CONGELA al solicitar: es lo que el alumno pidió, y que edite su perfil mañana
 * no cambia lo que la escuela va a emitir por esta solicitud. Si al emitir hace
 * falta corregirlo, es un acto del admin, no una reescritura silenciosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_factura', function (Blueprint $table) {
            $table->id();

            // El titular es SIEMPRE una matrícula: el autoservicio de factura es
            // del alumno y su familia. El aspirante paga su ficha, pero su
            // facturación la lleva admisiones a mano, no este portal.
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta');

            // Quién la pidió: el alumno o el familiar. Para poder decir «tu
            // solicitud» y para el rastro.
            $table->foreignId('solicitada_por')->nullable()->constrained('usuarios')->nullOnDelete();

            // Las operaciones (pagos cobrados) que se piden facturar.
            $table->json('pago_ids');

            // El receptor CONGELADO al solicitar. Es lo que se va a emitir; el
            // correo es de ENTREGA y va aparte de los datos fiscales.
            $table->string('receptor_rfc', 13);
            $table->string('receptor_razon_social', 255);
            $table->string('receptor_uso_cfdi', 5);
            $table->string('receptor_regimen_fiscal', 5);
            $table->string('receptor_cp', 5);
            $table->string('receptor_correo', 190)->nullable();

            // pendiente | emitida | rechazada
            //
            // «requiere_datos» NO es un estado de la solicitud: sin perfil fiscal
            // válido la solicitud no llega a crearse —se le pide al alumno que
            // complete sus datos antes—, así que una solicitud que existe SIEMPRE
            // trae receptor. El «requiere datos» vive antes, en el portal.
            $table->string('estado', 20)->default('pendiente');

            // Por qué se rechazó (obligatorio al rechazar, lo exige el servicio).
            $table->text('motivo_rechazo')->nullable();

            $table->foreignId('revisado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();

            // La factura que se emitió al atenderla.
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();

            $table->auditoria();

            // La bandeja se abre por aquí: lo pendiente, lo más viejo primero.
            $table->index(['estado', 'created_at']);
            $table->index(['matricula_oferta_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_factura');
    }
};
