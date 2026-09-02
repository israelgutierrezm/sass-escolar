<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuesto de egresos y centros de costo.
 *
 * ── Lo que este sistema NO tenía ───────────────────────────────────────────
 * Todo el dinero que ENTRA está medido hasta el último peso —cartera, cobro,
 * caja, CFDI, conciliación—, y del que SALE no había una sola tabla. Lo único
 * que el cierre fiscal llama «egresos» son notas de crédito, que no son gasto:
 * son ingreso que se reversa. Así que un presupuesto no se podía comparar
 * contra nada.
 *
 * ── Y esto NO es contabilidad ──────────────────────────────────────────────
 * No hay órdenes de compra, ni cuentas por pagar, ni CFDI recibidos que
 * validar. Es control presupuestal: cuánto se autorizó gastar en cada cosa y
 * cuánto se lleva. Media implementación de cuentas por pagar sería peor que
 * ninguna, porque se usaría como si fuera contabilidad.
 *
 * ── El ejercido tiene UNA sola fuente: los egresos registrados ─────────────
 * La tentación es derivarlo —«la nómina de este campus cuenta contra su centro
 * de costo»— y eso crea una segunda verdad: un número que cambia según de dónde
 * se mire y que nadie puede auditar renglón por renglón. La nómina entra al
 * presupuesto como un EGRESO más, con un acto deliberado que deja su rastro
 * (`origen`), y así «ejercido» significa lo mismo siempre.
 *
 * ── El centro de costo es una DIMENSIÓN, no una cuenta ─────────────────────
 * `campus_id` nullable a propósito: hay gasto que no es de ningún plantel
 * —licencias, dirección general— y obligarlo a elegir uno repartiría a ojo lo
 * que no se reparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('centros_costo')) {
            Schema::create('centros_costo', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('clave', 30)->unique();
                $tabla->string('nombre', 120);
                // NULL = no es de ningún plantel. Ver la nota de arriba.
                $tabla->foreignId('campus_id')->nullable()->constrained('campus');
                $tabla->string('notas', 255)->nullable();
                $tabla->boolean('activo')->default(true);
                $tabla->auditoria();
            });
        }

        if (! Schema::hasTable('partidas_presupuesto')) {
            Schema::create('partidas_presupuesto', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('clave', 30)->unique();
                $tabla->string('nombre', 120);
                $tabla->string('notas', 255)->nullable();
                $tabla->boolean('activo')->default(true);
                $tabla->auditoria();
            });
        }

        if (! Schema::hasTable('presupuestos')) {
            Schema::create('presupuestos', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('centro_costo_id')->constrained('centros_costo');
                $tabla->foreignId('partida_id')->constrained('partidas_presupuesto');
                // Por CICLO, como el presupuesto de becas: es la unidad en la
                // que una escuela planea. El año fiscal es de la contabilidad,
                // y esto no lo es.
                $tabla->foreignId('ciclo_id')->constrained('ciclos');
                $tabla->decimal('monto', 14, 2);
                $tabla->string('notas', 255)->nullable();
                $tabla->auditoria();

                // Una sola cifra por cruce: con dos, «cuánto se autorizó» no
                // tendría respuesta.
                $tabla->unique(['centro_costo_id', 'partida_id', 'ciclo_id'], 'presupuesto_unico');
            });
        }

        if (! Schema::hasTable('egresos')) {
            Schema::create('egresos', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->date('fecha');
                /*
                 * Los dos OBLIGATORIOS. Un egreso sin centro y sin partida no se
                 * puede comparar contra ningún presupuesto, así que sería un
                 * renglón que engorda el total y no explica nada — y el total
                 * es justo lo que se mira.
                 */
                $tabla->foreignId('centro_costo_id')->constrained('centros_costo');
                $tabla->foreignId('partida_id')->constrained('partidas_presupuesto');
                $tabla->foreignId('ciclo_id')->constrained('ciclos');
                $tabla->decimal('monto', 14, 2);
                $tabla->string('descripcion', 255);
                $tabla->string('beneficiario', 160)->nullable();
                $tabla->string('referencia', 100)->nullable();
                // El comprobante, en el disco privado. Es lo que le da autoridad
                // al renglón; el sistema no lo valida ni pretende timbrarlo.
                $tabla->string('comprobante_ruta', 255)->nullable();
                $tabla->string('comprobante_nombre', 160)->nullable();
                /*
                 * De dónde salió: capturado a mano, o traído de un periodo de
                 * nómina. Lo segundo lleva `origen_id` y su único, para que
                 * llevar la misma nómina dos veces no duplique el gasto más
                 * grande de la escuela.
                 */
                $tabla->string('origen', 20)->default('captura');
                $tabla->unsignedBigInteger('origen_id')->nullable();
                $tabla->auditoria();

                $tabla->index(['ciclo_id', 'centro_costo_id', 'partida_id']);
                $tabla->unique(['origen', 'origen_id', 'centro_costo_id'], 'egreso_origen_unico');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('egresos');
        Schema::dropIfExists('presupuestos');
        Schema::dropIfExists('partidas_presupuesto');
        Schema::dropIfExists('centros_costo');
    }
};
