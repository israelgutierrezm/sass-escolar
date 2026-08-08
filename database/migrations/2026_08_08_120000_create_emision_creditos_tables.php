<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que cada escuela ha consumido en XML de certificación y titulación, y con
 * qué lo paga.
 *
 * ── Por qué vive en la base CENTRAL y no en la de la escuela ───────────────
 * Es la organización que administra las escuelas quien cobra esto, no la
 * escuela. Guardarlo en el tenant significaría que el saldo vive dentro de la
 * base que la propia escuela administra —y que para saber cuánto se consumió en
 * total habría que recorrer todas las escuelas una por una—.
 *
 * ── Tres formas de pagarlo ─────────────────────────────────────────────────
 * - **prepago**: compra créditos antes y cada XML gasta uno. Sin saldo no se
 *   firma.
 * - **postpago**: se cuenta y se cobra al final del periodo.
 * - **ilimitado**: incluido en el servicio; se cuenta igual —hace falta para
 *   saber qué se está usando— pero nunca cobra.
 *
 * ── La regla que evita cobrar dos veces al mismo alumno ────────────────────
 * Un XML puede salir mal por un dato mal capturado y hay que rehacerlo. Eso no
 * es un consumo nuevo: es el mismo trámite del mismo alumno. Se reconoce por
 * **CURP + plan de estudios**, que es lo que identifica «este certificado de
 * esta persona para esta carrera» sin depender de folios que cambian al
 * regenerar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emision_saldos', function (Blueprint $table) {
            $table->id();

            // El tenant, por su id de cadena. Sin llave foránea a `tenants`
            // porque stancl guarda su id como string y aquí sólo hace falta la
            // referencia.
            $table->string('tenant_id', 191)->unique();

            // prepago | postpago | ilimitado
            $table->string('modalidad', 20)->default('postpago');

            /*
             * Sólo cuenta en prepago. Se permite negativo a propósito: si algún
             * día se firma sin saldo por una vía que no comprueba, el número
             * dice la verdad en vez de quedarse en cero y esconder la deuda.
             */
            $table->integer('creditos')->default(0);

            $table->text('notas')->nullable();

            $table->timestamps();
        });

        Schema::create('emision_consumos', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id', 191);
            // certificado | titulo
            $table->string('tipo', 20);

            /*
             * Lo que identifica el trámite. La CURP porque es de la persona y no
             * cambia; el plan porque el mismo alumno puede titularse de dos
             * carreras y eso SÍ son dos trámites.
             */
            $table->string('curp', 18);
            $table->string('plan_clave', 60);

            // El folio del documento que lo produjo, para poder rastrearlo.
            $table->string('referencia', 100)->nullable();

            /*
             * Si este renglón gastó un crédito. El primero de una pareja
             * CURP+plan cobra; los siguientes —regeneraciones— se registran
             * para que quede constancia, pero con `false`.
             */
            $table->boolean('cobrado')->default(true);

            $table->timestamps();

            // Por aquí se pregunta «¿ya se cobró este trámite?».
            $table->index(['tenant_id', 'tipo', 'curp', 'plan_clave'], 'consumo_tramite');
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('emision_compras', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id', 191);
            $table->unsignedInteger('creditos');
            $table->decimal('monto', 12, 2)->nullable();
            $table->string('referencia', 100)->nullable();

            // El comprobante de la transferencia, en disco privado.
            $table->string('comprobante', 255)->nullable();

            // pendiente | aprobada | rechazada
            $table->string('estado', 20)->default('pendiente');
            $table->text('motivo_rechazo')->nullable();

            /*
             * Quién la revisó: un administrador de la ORGANIZACIÓN, no de la
             * escuela. Por eso apunta a `super_admins` y no a los usuarios del
             * tenant —que ni siquiera están en esta base—.
             */
            $table->foreignId('revisado_por')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();

            $table->timestamps();

            $table->index(['estado', 'created_at']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emision_compras');
        Schema::dropIfExists('emision_consumos');
        Schema::dropIfExists('emision_saldos');
    }
};
