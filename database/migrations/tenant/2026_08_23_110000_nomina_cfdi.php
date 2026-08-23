<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 10 · Nómina y RH, cuarta rebanada — el CFDI de nómina.
 *
 * Aquí entran los campos que las rebanadas anteriores dejaron fuera A PROPÓSITO
 * porque sólo los lee el timbrado: `clave_sat` de los conceptos y el régimen
 * fiscal del empleado. Se prometió que llegarían con su lector, y éste es.
 *
 * ── El receptor REUSA `datos_facturacion` ─────────────────────────────────
 * El RFC, el régimen fiscal y el código postal de una persona ya viven ahí —es
 * la tabla que la facturación usa para el receptor— y son los mismos datos:
 * quien cobra nómina y quien pide una factura son la misma persona con la misma
 * identidad fiscal. Una tabla «datos fiscales del empleado» sería una segunda
 * verdad sobre el mismo RFC, con la pregunta de a cuál creerle el día que no
 * coincidan.
 *
 * ── Las claves del SAT son de CATÁLOGO, no de código ──────────────────────
 * `conceptos_nomina.clave_sat` y `tipos_contrato.clave_sat` se capturan. Se
 * siembran las que son estándar y no admiten discusión —sueldos 001, seguridad
 * social 001, ISR 002—, y **las que dependen de cómo opere cada escuela se
 * dejan en NULL para que el validador las reclame**: inventarle una clave al
 * descuento por préstamo daría un comprobante que el SAT acepta diciendo algo
 * que nadie decidió.
 *
 * ── El estado del timbrado es del RECIBO, no del periodo ──────────────────
 * Como se anotó en la rebanada anterior: el SAT puede rechazar uno y aceptar
 * los otros cuarenta, igual que la SEP con los títulos de un lote. `uuid`,
 * `xml_ruta`, `timbrado_en` y `error_timbrado` van en cada recibo.
 */
return new class extends Migration
{
    /**
     * Claves del catálogo c_TipoPercepcion / c_TipoDeduccion del SAT.
     *
     * Sólo las que son estándar. `prestamo` se queda sin clave a propósito: en
     * el catálogo del SAT cae en «Otros» y qué sea exactamente depende de cómo
     * lo tenga acordado cada escuela con su contador.
     */
    private const CLAVES_CONCEPTO = [
        'sueldo' => '001',                    // Sueldos, salarios, rayas y jornales
        'horas_trabajadas' => '001',          // También es sueldo ordinario
        'asignaturas_impartidas' => '001',
        'bono_puntualidad' => '010',          // Premios por puntualidad
        'despensa' => '029',                  // Vales de despensa
        'imss' => '001',                      // Deducción: seguridad social
        'isr' => '002',                       // Deducción: ISR
    ];

    /** c_TipoContrato. `por_asignatura` se deja sin clave: depende de la escuela. */
    private const CLAVES_CONTRATO = [
        'base' => '01',           // Por tiempo indeterminado
        'determinado' => '03',    // Por tiempo determinado
        'honorarios' => '08',     // Sin relación de trabajo
    ];

    public function up(): void
    {
        $this->clavesDelSat();
        $this->datosDelPatron();
        $this->estadoDelTimbrado();
    }

    public function down(): void
    {
        foreach ([
            'conceptos_nomina' => ['clave_sat'],
            'tipos_contrato' => ['clave_sat'],
            'expedientes_laborales' => ['regimen_sat'],
            'periodos_nomina' => ['periodicidad_sat'],
            'emisores_fiscales' => ['registro_patronal'],
            'recibos_nomina' => ['uuid', 'xml_ruta', 'timbrado_en', 'error_timbrado', 'pac'],
        ] as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn($columna));
                }
            }
        }
    }

    private function clavesDelSat(): void
    {
        if (! Schema::hasColumn('conceptos_nomina', 'clave_sat')) {
            Schema::table('conceptos_nomina', fn (Blueprint $t) => $t
                ->string('clave_sat', 10)->nullable()->after('naturaleza'));
        }

        if (! Schema::hasColumn('tipos_contrato', 'clave_sat')) {
            Schema::table('tipos_contrato', fn (Blueprint $t) => $t
                ->string('clave_sat', 2)->nullable()->after('nombre'));
        }

        foreach (self::CLAVES_CONCEPTO as $clave => $sat) {
            DB::table('conceptos_nomina')->where('clave', $clave)->update(['clave_sat' => $sat]);
        }

        foreach (self::CLAVES_CONTRATO as $clave => $sat) {
            DB::table('tipos_contrato')->where('clave', $clave)->update(['clave_sat' => $sat]);
        }
    }

    private function datosDelPatron(): void
    {
        // c_TipoRegimen del empleado: 02 sueldos, 09 asimilados… Es del vínculo
        // laboral y no de la persona: alguien puede ser asimilado en una plaza
        // y de sueldos en otra.
        if (! Schema::hasColumn('expedientes_laborales', 'regimen_sat')) {
            Schema::table('expedientes_laborales', fn (Blueprint $t) => $t
                ->string('regimen_sat', 3)->nullable()->after('tipo_contrato_id'));
        }

        /*
         * La periodicidad es del PERIODO, no del empleado: la misma persona
         * puede cobrar en una quincena ordinaria y en un extraordinario de
         * aguinaldo, y el SAT quiere saber cuál es cada comprobante.
         */
        if (! Schema::hasColumn('periodos_nomina', 'periodicidad_sat')) {
            Schema::table('periodos_nomina', fn (Blueprint $t) => $t
                ->string('periodicidad_sat', 2)->nullable()->after('fecha_pago'));
        }

        // El registro patronal ante el IMSS. Va en el emisor porque es de la
        // razón social que paga, y una escuela puede tener varias.
        if (! Schema::hasColumn('emisores_fiscales', 'registro_patronal')) {
            Schema::table('emisores_fiscales', fn (Blueprint $t) => $t
                ->string('registro_patronal', 20)->nullable()->after('regimen_fiscal'));
        }
    }

    private function estadoDelTimbrado(): void
    {
        Schema::table('recibos_nomina', function (Blueprint $t) {
            if (! Schema::hasColumn('recibos_nomina', 'uuid')) {
                // Único: dos recibos con el mismo folio fiscal es imposible, y
                // si pasara sería porque se guardó dos veces la misma respuesta.
                $t->string('uuid', 36)->nullable()->unique()->after('incidencias');
            }

            if (! Schema::hasColumn('recibos_nomina', 'xml_ruta')) {
                $t->string('xml_ruta', 500)->nullable()->after('uuid');
            }

            if (! Schema::hasColumn('recibos_nomina', 'pac')) {
                $t->string('pac', 40)->nullable()->after('xml_ruta');
            }

            if (! Schema::hasColumn('recibos_nomina', 'timbrado_en')) {
                $t->timestamp('timbrado_en')->nullable()->after('pac');
            }

            if (! Schema::hasColumn('recibos_nomina', 'error_timbrado')) {
                // Lo que el SAT contestó. Se guarda para poder enseñárselo tal
                // cual: un rechazo del SAT no es un error del sistema.
                $t->text('error_timbrado')->nullable()->after('timbrado_en');
            }
        });
    }
};
