<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El convenio de pago: lo que la escuela acuerda con quien no puede pagar de
 * golpe.
 *
 * ── Una fila del catálogo que llevaba tiempo prometiendo algo ──────────────
 * `situaciones_pago` trae «Con convenio de pago» desde la entrega 7.1 y NADA la
 * escribía: `EvaluadorDeudor` sólo sabe poner «moroso» o «al corriente». Así
 * que a quien firmaba un convenio el barrido nocturno lo marcaba moroso igual,
 * y con la situación equivocada puesta en `bloquea` se le podía cerrar la
 * inscripción por una deuda que ya estaba acordada. Es el mismo defecto de
 * `etapas_crm` sembrada sin usar y de `cierra_el_embudo` que sólo se dibujaba.
 *
 * ── Un convenio NO perdona: REPROGRAMA ─────────────────────────────────────
 * La suma de las parcialidades tiene que ser exactamente el saldo cubierto. Si
 * pudiera ser menor, perdonar deuda se podría hacer desde esta pantalla, sin el
 * permiso de condonar y sin su bitácora — y entonces habría dos puertas para lo
 * mismo y sólo una vigilada. Para perdonar se condona primero, y después se
 * acuerda lo que quede.
 *
 * ── Los adeudos originales no se cancelan: pasan a `en_convenio` ───────────
 * Cancelarlos borraría qué se debía y desde cuándo. `en_convenio` dice «esto lo
 * cubre otro arreglo», y como TODO el motor de cartera filtra por la lista
 * blanca `[pendiente, parcial]` —el estado de cuenta, la mora, el evaluador de
 * deudores, el registrador de pagos—, un estatus nuevo no se cuenta dos veces
 * ni se recarga ni se puede pagar por la puerta de atrás. Esa lista blanca es
 * lo que hace barato agregarlo; con una lista negra («todo menos pagado») el
 * valor nuevo se habría colado en los seis sitios.
 *
 * ── Un convenio cubre adeudos de UN SOLO concepto ──────────────────────────
 * Porque el CFDI y el complemento educativo se emiten contra el concepto: una
 * parcialidad que mezclara colegiatura con una reposición de credencial haría
 * que un comprobante dijera «enseñanza» sobre dinero que no lo es —o, al revés,
 * que una colegiatura pagada en parcialidades dejara de ser deducible—. Si hay
 * que acordar dos conceptos, son dos convenios.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('convenios_pago')) {
            Schema::create('convenios_pago', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('matricula_oferta_id')->constrained('matricula_oferta');
                // El único concepto que cubre. Ver la nota de arriba.
                $tabla->foreignId('concepto_id')->constrained('conceptos_pago');
                /*
                 * Obligatorio: un convenio mueve cuándo se le cobra a alguien, y
                 * sin la razón escrita nadie puede explicar dentro de un año por
                 * qué a este alumno se le dieron seis meses. Mismo criterio que
                 * la nota de crédito.
                 */
                $tabla->string('motivo', 255);
                $tabla->date('firmado_en');
                // Congelado al firmar: lo que se acordó cubrir. Recalcularlo al
                // mirarlo haría que el acuerdo cambiara solo.
                $tabla->decimal('monto_cubierto', 12, 2);
                $tabla->string('estatus', 20)->default('vigente');
                $tabla->foreignId('autorizado_por')->nullable()->constrained('usuarios');
                $tabla->timestamp('cerrado_en')->nullable();
                $tabla->string('motivo_cierre', 255)->nullable();
                $tabla->auditoria();

                $tabla->index(['matricula_oferta_id', 'estatus']);
            });
        }

        if (! Schema::hasTable('convenio_adeudo')) {
            Schema::create('convenio_adeudo', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('convenio_id')->constrained('convenios_pago')->cascadeOnDelete();
                $tabla->foreignId('adeudo_id')->constrained('adeudos');
                // Lo que ese cargo debía el día que se firmó. Es lo que después
                // permite explicar de dónde salió el total acordado.
                $tabla->decimal('saldo_cubierto', 12, 2);
                $tabla->auditoria();

                // Un cargo no puede estar en dos convenios: si no, el mismo
                // dinero se acordaría dos veces. Cancelar un convenio BORRA sus
                // filas de aquí —no las da de baja lógica— justo para que el
                // cargo pueda volver a acordarse.
                $tabla->unique(['adeudo_id']);
            });
        }

        if (! Schema::hasColumn('adeudos', 'convenio_id')) {
            Schema::table('adeudos', function (Blueprint $tabla) {
                // Puesto: este cargo ES una parcialidad del convenio. Los cargos
                // que el convenio CUBRE se identifican por el pivote, no por
                // aquí — son dos relaciones distintas y confundirlas haría que
                // una parcialidad se cubriera a sí misma.
                $tabla->foreignId('convenio_id')->nullable()->after('concepto_plan_id')
                    ->constrained('convenios_pago');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('adeudos', 'convenio_id')) {
            Schema::table('adeudos', function (Blueprint $tabla) {
                $tabla->dropForeign(['convenio_id']);
                $tabla->dropColumn('convenio_id');
            });
        }

        Schema::dropIfExists('convenio_adeudo');
        Schema::dropIfExists('convenios_pago');
    }
};
