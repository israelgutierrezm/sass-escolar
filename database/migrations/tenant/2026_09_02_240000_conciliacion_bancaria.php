<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conciliación bancaria: cuadrar lo que dice el banco contra lo que dice el
 * sistema.
 *
 * ── Las dos fallas que sólo esto encuentra ─────────────────────────────────
 * Ninguna de las dos revienta, ninguna avisa, y las dos cuestan dinero:
 *
 * 1. **Entró dinero que nadie registró.** Una familia transfiere y jamás sube
 *    su comprobante. El banco lo tiene, la cartera dice que debe, y se le
 *    cobra un adeudo que ya pagó.
 * 2. **Se registró un cobro cuyo dinero nunca llegó.** Un comprobante aprobado
 *    sobre una imagen repetida, o un depósito de caja capturado que no se hizo.
 *    La cartera dice pagado y el banco nunca lo vio. Así es como desaparece el
 *    efectivo.
 *
 * Hoy no hay nada en la aplicación que dispare la revisión —igual que con los
 * CFDI cancelados desde el portal del PAC—, así que esto es una pantalla que
 * alguien abre a propósito, no un efecto secundario de cobrar.
 *
 * ── LA REGLA: no escribe en los pagos ni en la cartera ─────────────────────
 * Mismo criterio que `ConciliadorCfdi` y que `acadion:auditar-datos`. El
 * insumo es un CSV que cualquiera puede editar en su máquina; si de él
 * dependiera el estatus de un pago, un archivo retocado movería lo que un
 * alumno debe. Aquí se guarda el VÍNCULO entre el renglón del banco y el
 * movimiento del sistema, y se reporta lo que no casa. Resolverlo es un acto
 * deliberado, con su pantalla y su permiso.
 *
 * ── El renglón importado es INMUTABLE ──────────────────────────────────────
 * Es lo que dijo el banco. Si se pudiera corregir, la conciliación cuadraría
 * editando la evidencia, que es exactamente lo contrario de para qué existe.
 * Lo que sí se anota son cosas NUESTRAS sobre él: con qué casó, y —cuando no
 * es un cobro— qué es.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Cómo se lee el archivo de ESTA cuenta.
         *
         * Va en la cuenta y no cableado por banco, que es la regla 4: la
         * escuela agrega su banco sin que nadie programe. Y no es un catálogo
         * de filas porque no es algo enumerable —es la forma de un archivo—,
         * así que una tabla no aportaría nada sobre un JSON.
         *
         * Por cuenta y no por banco porque no hay catálogo de bancos: una
         * cuenta pertenece a uno solo, así que repetir el mapeo entre dos
         * cuentas del mismo banco cuesta un formulario y ahorra una tabla que
         * habría que inventar.
         */
        if (! Schema::hasColumn('cuentas_bancarias', 'mapeo_estado_cuenta')) {
            Schema::table('cuentas_bancarias', function (Blueprint $tabla) {
                $tabla->json('mapeo_estado_cuenta')->nullable()->after('instrucciones');
            });
        }

        if (! Schema::hasTable('estados_cuenta_bancaria')) {
            Schema::create('estados_cuenta_bancaria', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias');
                $tabla->date('periodo_inicio');
                $tabla->date('periodo_fin');
                /*
                 * Los dos saldos son OBLIGATORIOS, y son lo que prueba que el
                 * archivo está completo: inicial + suma de movimientos tiene
                 * que dar el final. Sin ellos, un CSV cortado en el renglón 200
                 * concilia impecable y esconde media quincena — y una
                 * conciliación en verde sobre datos incompletos es peor que no
                 * tener conciliación.
                 */
                $tabla->decimal('saldo_inicial', 14, 2);
                $tabla->decimal('saldo_final', 14, 2);
                $tabla->unsignedInteger('movimientos')->default(0);
                // El archivo tal como llegó, en el disco privado: trae nombres
                // y referencias de quien paga.
                $tabla->string('archivo_ruta', 255)->nullable();
                $tabla->string('archivo_nombre', 160)->nullable();
                $tabla->auditoria();

                $tabla->index(['cuenta_bancaria_id', 'periodo_inicio']);
            });
        }

        if (! Schema::hasTable('movimientos_bancarios')) {
            Schema::create('movimientos_bancarios', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('estado_cuenta_id')->constrained('estados_cuenta_bancaria')->cascadeOnDelete();
                // Denormalizada a propósito: casi toda consulta de conciliación
                // es «los movimientos de esta cuenta», y llegar por el estado
                // de cuenta obliga a un join en cada una.
                $tabla->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias');
                $tabla->date('fecha');
                $tabla->string('descripcion', 255);
                $tabla->string('referencia', 120)->nullable();
                /*
                 * CON SIGNO: entradas en positivo, salidas en negativo. Un solo
                 * campo y no dos columnas cargo/abono —eso es la forma del
                 * archivo, no la del hecho—, así que la suma de la columna es
                 * el movimiento neto del periodo y el cuadre del saldo es una
                 * resta.
                 */
                $tabla->decimal('monto', 14, 2);
                /*
                 * La huella del renglón: es lo que hace idempotente reimportar.
                 * Ojo, NO es única — dos familias transfiriendo $2,500 el mismo
                 * día con la misma referencia en blanco son dos movimientos
                 * legítimos e idénticos. Lo que se cuenta son OCURRENCIAS: se
                 * insertan las que trae el archivo menos las que ya están.
                 */
                $tabla->string('huella', 64);
                /*
                 * Qué es este renglón cuando NO es un cobro: comisión,
                 * intereses, un traspaso entre cuentas propias, una devolución.
                 * Sin poder decirlo, la conciliación nunca cierra, y una lista
                 * que nunca llega a cero enseña a no mirarla.
                 */
                $tabla->string('clasificacion', 30)->nullable();
                $tabla->string('nota', 255)->nullable();
                $tabla->auditoria();

                $tabla->index(['cuenta_bancaria_id', 'fecha']);
                $tabla->index(['cuenta_bancaria_id', 'huella']);
            });
        }

        if (! Schema::hasTable('conciliacion_partidas')) {
            Schema::create('conciliacion_partidas', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('movimiento_bancario_id')->constrained('movimientos_bancarios')->cascadeOnDelete();
                /*
                 * Exactamente uno de los dos, con CHECK abajo. Es el mismo
                 * mecanismo del titular dual de `adeudos`, y por eso van con
                 * `constrained()` a secas: MySQL rechaza con el error 3823 una
                 * columna que participa en un CHECK y además tiene acción
                 * referencial, y `nullOnDelete` dejaría la partida sin ninguno
                 * de los dos lados — justo lo que el CHECK impide.
                 */
                $tabla->foreignId('pago_id')->nullable()->constrained('pagos');
                $tabla->foreignId('deposito_caja_id')->nullable()->constrained('depositos_caja');
                /*
                 * Cuánto de ESTE renglón corresponde a ESE movimiento. Un
                 * renglón casa con VARIOS: una liquidación de pasarela son doce
                 * pagos en una sola línea, y un padre que paga a dos hijos en
                 * una transferencia son dos. Con 1:1 esos casos no se podrían
                 * conciliar y alguien acabaría inventando el pareo.
                 */
                $tabla->decimal('monto_aplicado', 14, 2);
                // Lo propuso el sistema o lo dijo una persona. Se guarda porque
                // son dos grados de confianza distintos al auditar.
                $tabla->boolean('automatica')->default(false);
                $tabla->auditoria();

                // Un movimiento del sistema se concilia una sola vez: si no, el
                // mismo pago cuadraría dos renglones del banco y el faltante se
                // escondería.
                $tabla->unique(['pago_id']);
                $tabla->unique(['deposito_caja_id']);
            });

            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement(
                    'ALTER TABLE conciliacion_partidas ADD CONSTRAINT chk_conciliacion_partidas_origen CHECK (
                        (pago_id IS NOT NULL) + (deposito_caja_id IS NOT NULL) = 1
                    )'
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conciliacion_partidas');
        Schema::dropIfExists('movimientos_bancarios');
        Schema::dropIfExists('estados_cuenta_bancaria');

        if (Schema::hasColumn('cuentas_bancarias', 'mapeo_estado_cuenta')) {
            Schema::table('cuentas_bancarias', function (Blueprint $tabla) {
                $tabla->dropColumn('mapeo_estado_cuenta');
            });
        }
    }
};
