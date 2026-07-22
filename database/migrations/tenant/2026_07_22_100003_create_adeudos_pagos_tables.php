<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El núcleo transaccional de finanzas: lo que se debe, lo que se pagó, qué
 * cubrió cada pago y cómo fue cambiando la situación financiera del alumno.
 *
 * DECISIÓN VINCULANTE (docs/decisiones.md): `adeudos` y `pagos` NO cuelgan
 * obligatoriamente de `matricula_oferta`. Un aspirante paga su ficha y su
 * inscripción ANTES de existir como alumno —de hecho, pagar suele ser el
 * requisito para que se le genere la matrícula—, así que con
 * `matricula_oferta_id` NOT NULL ese dinero no tendría dónde registrarse.
 *
 * Por eso ambas tablas llevan `matricula_oferta_id` nullable + `aspirante_id`
 * nullable, con **exactamente uno de los dos presente**, y la conversión
 * aspirante → alumno los re-liga a la matrícula nueva dentro de la misma
 * transacción en la que se genera la matrícula (`ReligadorFinanzas`).
 *
 * La alternativa —una tabla `pagos_admision` aparte— habría duplicado el motor
 * de cobro y partido el estado de cuenta del alumno en dos justo en el pago que
 * más se reclama después: el de inscripción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adeudos', function (Blueprint $table) {
            $table->id();

            // Exactamente uno de estos dos. Ver la nota de arriba y el CHECK
            // que se agrega al final de la migración.
            $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');
            $table->foreignId('aspirante_id')->nullable()->constrained('aspirantes');

            $table->foreignId('concepto_id')->constrained('conceptos_pago');
            // De qué regla salió. Nullable porque un cargo puede capturarse a
            // mano (una reposición de credencial, una multa de biblioteca) sin
            // que exista regla que lo genere.
            $table->foreignId('regla_id')->nullable()->constrained('reglas_generacion')->nullOnDelete();
            $table->foreignId('ciclo_id')->nullable()->constrained('ciclos');

            // "Marzo 2026", "Semana 12", "2026-2027/1". Es la etiqueta que hace
            // idempotente la generación: la misma regla + el mismo periodo no
            // debe generar dos adeudos.
            $table->string('periodo_etiqueta', 50)->nullable();

            $table->decimal('monto', 10, 2);
            $table->decimal('monto_recargos', 10, 2)->default(0);
            $table->decimal('monto_descuentos', 10, 2)->default(0);
            $table->decimal('monto_total', 10, 2);

            $table->date('fecha_generacion');
            $table->date('fecha_vencimiento');

            // pendiente / parcial / pagado / cancelado / condonado.
            // Va como varchar con constantes en el modelo y NO como catálogo
            // TENANT-CONFIG: es la máquina de estados del código —lo que sabe
            // interpretar el motor de cobro—, no algo que una escuela deba
            // renombrar. Mismo criterio que `actas.situacion`.
            $table->string('estatus', 20)->default('pendiente');

            $table->auditoria();

            // El estado de cuenta se consulta siempre por titular + estatus.
            $table->index(['matricula_oferta_id', 'estatus']);
            $table->index(['aspirante_id', 'estatus']);
            // La generación idempotente pregunta justo por esto.
            $table->index(['regla_id', 'periodo_etiqueta']);
        });

        Schema::create('pagos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');
            $table->foreignId('aspirante_id')->nullable()->constrained('aspirantes');

            // La spec lo describía como varchar; es catálogo por la regla del
            // proyecto y porque el CFDI necesita su `clave_sat`.
            $table->foreignId('metodo_pago_id')->constrained('metodos_pago');

            $table->decimal('monto', 10, 2);
            $table->string('referencia', 100)->nullable();
            $table->string('pasarela', 30)->nullable();      // conekta / openpay / stripe
            $table->string('pasarela_txn_id', 120)->nullable();

            // pendiente / completado / fallido / reembolsado. Nace en
            // `pendiente` cuando el método `requiere_confirmacion`: un pago por
            // pasarela no es dinero hasta que llega el webhook. Un pago en
            // ventanilla nace completado.
            $table->string('estatus', 20)->default('pendiente');

            $table->timestamp('momento')->nullable();

            $table->auditoria();

            $table->index(['matricula_oferta_id', 'estatus']);
            $table->index(['aspirante_id', 'estatus']);
            $table->index('pasarela_txn_id');
        });

        // Qué adeudos cubrió cada pago. `monto_aplicado` es lo que hace posible
        // el pago parcial (un abono a un adeudo) y el split (un depósito que
        // liquida tres mensualidades). Sin esa columna, un pago solo podría
        // cubrir adeudos completos.
        Schema::create('pago_adeudo', function (Blueprint $table) {
            $table->foreignId('pago_id')->constrained('pagos')->cascadeOnDelete();
            $table->foreignId('adeudo_id')->constrained('adeudos');
            $table->decimal('monto_aplicado', 10, 2);
            $table->auditoria();

            $table->primary(['pago_id', 'adeudo_id']);
        });

        // Historial de la situación financiera del alumno (corriente, moroso,
        // bloqueado). Append-only: un bloqueo levantado no se borra, se agrega
        // el renglón que lo levanta. La pregunta que responde después es "¿por
        // qué no se pudo reinscribir en marzo?", y para eso hace falta saber
        // qué situación tenía ENTONCES, no cuál tiene hoy.
        Schema::create('bitacora_situacion_financiera', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('situacion_id')->constrained('situaciones_pago');
            $table->string('motivo', 255)->nullable();
            $table->timestamp('momento');
            $table->auditoria();

            $table->index(['matricula_oferta_id', 'momento']);
        });

        // La regla "exactamente uno de los dos" se valida en la aplicación
        // (`Adeudo::titularValido()`, `ReligadorFinanzas`), pero MySQL 8 sabe
        // imponerla y una red de seguridad tan barata no debería depender de
        // que todo el código pase siempre por el mismo camino. Solo en MySQL:
        // SQLite —el motor de phpunit— no admite ALTER TABLE ADD CONSTRAINT.
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['adeudos', 'pagos'] as $tabla) {
                DB::statement(
                    "ALTER TABLE {$tabla} ADD CONSTRAINT chk_{$tabla}_titular CHECK (
                        (matricula_oferta_id IS NOT NULL) + (aspirante_id IS NOT NULL) = 1
                    )"
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_situacion_financiera');
        Schema::dropIfExists('pago_adeudo');
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('adeudos');
    }
};
