<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 10 · Nómina y RH, tercera rebanada — el periodo, el recibo y el
 * cálculo.
 *
 * ── El recibo se MATERIALIZA ──────────────────────────────────────────────
 * Sus renglones guardan el importe que se calculó, no una referencia al sueldo
 * vigente. Es la misma decisión que `esquema_evaluacion` con las
 * calificaciones, que la factura con su emisor y que el acta impresa: un
 * documento que se recalcula al mirarlo cambia de contenido cuando alguien
 * actualiza un dato de hoy, y un recibo de nómina es un hecho fechado que hay
 * que poder explicar dentro de cinco años.
 *
 * Por eso el recibo además APUNTA al esquema con el que se calculó: sin eso,
 * explicar de dónde salió un número obliga a reconstruir qué sueldo regía.
 *
 * ── Las fórmulas hacen porcentaje sobre una base, con tope. Nada más ──────
 * `formulas_nomina` cubre lo que de verdad es un porcentaje: la cuota obrera
 * del IMSS, un descuento proporcional, un bono sobre lo gravable.
 *
 * **El ISR NO se calcula con esto, y es deliberado.** El ISR de nómina sale de
 * la tarifa por rangos del artículo 96 más el subsidio al empleo: no es un
 * factor plano. Sembrar una fórmula de ISR con un porcentaje inventado daría un
 * número que parece bueno, que alguien enteraría al SAT y que nadie descubriría
 * hasta la primera revisión. El concepto `isr` se queda SIN fórmula y se captura
 * a mano hasta que exista la tarifa oficial.
 *
 * ── Y aquí es donde `es_gravable` por fin tiene lector ────────────────────
 * La base `percepciones_gravables` lo consulta. Se declaró en la rebanada
 * anterior porque es una propiedad que la escuela decide; ahora además se usa.
 *
 * ── Un empleado, un recibo por periodo ────────────────────────────────────
 * Único sobre (periodo, expediente). Es la protección que de verdad importa
 * contra pagar dos veces. **Lo que NO se prohíbe es que dos periodos se
 * traslapen**: una quincena y un periodo extraordinario de aguinaldo se
 * enciman de forma legítima, y prohibirlo obligaría a inventar un rodeo. A
 * cambio, dos periodos traslapados cuentan las MISMAS checadas, así que la
 * pantalla lo advierte al crear uno que se encima con otro.
 *
 * ── El timbrado NO es un estado del periodo ───────────────────────────────
 * La spec ponía «timbrado» junto a abierto y pagado. Va a ser una propiedad de
 * cada RECIBO, igual que en los lotes de certificación el reenvío es por título
 * y no por lote: el SAT puede rechazar uno y aceptar los otros cuarenta, y un
 * estado de periodo obligaría a elegir entre mentir o bloquear a todos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->formulas();
        $this->periodos();
        $this->recibos();
    }

    public function down(): void
    {
        Schema::dropIfExists('recibo_conceptos');
        Schema::dropIfExists('recibos_nomina');
        Schema::dropIfExists('periodos_nomina');

        if (Schema::hasColumn('conceptos_nomina', 'formula_id')) {
            Schema::table('conceptos_nomina', function (Blueprint $t) {
                $t->dropForeign(['formula_id']);
                $t->dropColumn('formula_id');
            });
        }

        Schema::dropIfExists('formulas_nomina');
    }

    private function formulas(): void
    {
        if (! Schema::hasTable('formulas_nomina')) {
            Schema::create('formulas_nomina', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);

                /*
                 * Sobre QUÉ se aplica el factor. Relacional y no un blob: son
                 * tres columnas que cualquiera puede leer con un SELECT, y la
                 * spec lo pedía así por algo — una fórmula guardada como texto
                 * hay que interpretarla en el código para saber qué hace.
                 */
                $t->string('base', 40);
                $t->decimal('factor', 8, 6);

                // Tope en importe, para las cuotas que se topan.
                $t->decimal('tope', 12, 2)->nullable();

                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        if (! Schema::hasColumn('conceptos_nomina', 'formula_id')) {
            Schema::table('conceptos_nomina', fn (Blueprint $t) => $t
                ->foreignId('formula_id')->nullable()->after('es_gravable')
                ->constrained('formulas_nomina')->nullOnDelete());
        }

        /*
         * Una sola fórmula sembrada, y a propósito: la cuota obrera del IMSS es
         * de verdad un porcentaje sobre lo gravable. Todo lo demás que una
         * escuela quiera lo arma en su pantalla.
         */
        DB::table('formulas_nomina')->updateOrInsert(['clave' => 'imss_obrero'], [
            'nombre' => 'Cuota obrera IMSS (aproximada)',
            'base' => 'percepciones_gravables',
            'factor' => 0.0275,
            'tope' => null,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $imss = DB::table('formulas_nomina')->where('clave', 'imss_obrero')->value('id');

        DB::table('conceptos_nomina')->where('clave', 'imss')->update(['formula_id' => $imss]);
    }

    private function periodos(): void
    {
        if (Schema::hasTable('periodos_nomina')) {
            return;
        }

        Schema::create('periodos_nomina', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 120);
            $t->date('fecha_inicio');
            $t->date('fecha_fin');
            $t->date('fecha_pago')->nullable();

            // Null = toda la escuela. Con campus, sólo quien está adscrito ahí.
            $t->foreignId('campus_id')->nullable()->constrained('campus')->nullOnDelete();

            /*
             * Columna y no catálogo: cada estado habilita gestos distintos en
             * el código —abierto se calcula, cerrado ya no se toca—, así que
             * una fila nueva en un catálogo no haría nada. Es la misma razón
             * por la que `naturaleza` tampoco es tabla.
             */
            $t->string('estado', 20)->default('abierto');

            $t->text('notas')->nullable();
            $t->auditoria();

            $t->index(['fecha_inicio', 'fecha_fin']);
        });
    }

    private function recibos(): void
    {
        if (! Schema::hasTable('recibos_nomina')) {
            Schema::create('recibos_nomina', function (Blueprint $t) {
                $t->id();
                $t->foreignId('periodo_nomina_id')->constrained('periodos_nomina')->cascadeOnDelete();
                $t->foreignId('expediente_laboral_id')->constrained('expedientes_laborales');

                // Con qué sueldo se calculó. Sin esto, explicar un número de
                // hace dos años obliga a reconstruir qué esquema regía.
                $t->foreignId('esquema_percepcion_id')->nullable()->constrained('esquemas_percepcion')->nullOnDelete();

                $t->decimal('total_percepciones', 12, 2)->default(0);
                $t->decimal('total_deducciones', 12, 2)->default(0);
                $t->decimal('neto', 12, 2)->default(0);

                /*
                 * Lo que el cálculo no pudo resolver: checadas sin salida,
                 * sueldo sin fijar. Se escribe en el recibo y no en un registro
                 * porque quien revisa la nómina es quien tiene que verlo.
                 */
                $t->text('incidencias')->nullable();

                $t->auditoria();

                // Un empleado, un recibo por periodo. Es la protección que de
                // verdad importa contra pagar dos veces.
                $t->unique(['periodo_nomina_id', 'expediente_laboral_id'], 'recibo_unico_por_periodo');
            });
        }

        if (Schema::hasTable('recibo_conceptos')) {
            return;
        }

        Schema::create('recibo_conceptos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recibo_nomina_id')->constrained('recibos_nomina')->cascadeOnDelete();
            $t->foreignId('concepto_nomina_id')->constrained('conceptos_nomina');

            $t->decimal('importe', 12, 2);

            // Horas o asignaturas, cuando el renglón sale de contar algo. Null
            // cuando es un importe a secas: un «1» inventado haría creer que se
            // contó una unidad de algo.
            $t->decimal('cantidad', 10, 2)->nullable();

            $t->string('detalle', 200)->nullable();

            /*
             * Agregado a mano después de calcular. Se distingue porque el
             * recálculo avisa de cuántos va a tirar: perder en silencio un
             * descuento capturado a mano es pagarle de más a alguien.
             */
            $t->boolean('manual')->default(false);

            $t->unsignedSmallInteger('orden')->default(0);
            $t->auditoria();

            $t->index(['recibo_nomina_id', 'orden']);
        });
    }
};
