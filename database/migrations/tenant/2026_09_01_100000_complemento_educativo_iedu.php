<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El complemento IEDU: lo que convierte una factura en un recibo deducible.
 *
 * ── Por qué importa ────────────────────────────────────────────────────────
 * Un padre deduce las colegiaturas de sus hijos hasta bachillerato, y sólo si
 * el CFDI trae el complemento «Instituciones Educativas Privadas». Sin él, la
 * factura es válida ante el SAT, se timbra sin un solo error, y en abril el
 * padre descubre que no puede deducirla. Es el peor tipo de defecto de este
 * módulo: no falla, no avisa, y se nota seis meses tarde y ante un tercero.
 *
 * ── Qué faltaba de verdad ──────────────────────────────────────────────────
 * Casi nada de dato: la CURP vive en `personas`, el RVOE en
 * `planes_estudio.rvoe` y el nivel en `niveles_estudio`. Lo que faltaba era el
 * MAPEO: cuál de los niveles de esta escuela corresponde a cuál de los cinco
 * que el SAT reconoce, que no se puede adivinar —una escuela llama «Media
 * superior» a lo que otra llama «Preparatoria»— ni cablear.
 *
 * ── `niveles_estudio.nivel_iedu`: nulo significa NO DEDUCIBLE ──────────────
 * Y ése es el valor por omisión a propósito. El catálogo del SAT llega hasta
 * bachillerato: licenciatura, maestría y doctorado NO son deducibles, y son la
 * mayoría de lo que ofertan las escuelas de este sistema. Un valor por omisión
 * que marcara todo como deducible produciría complementos falsos en masa.
 *
 * Se siembran los tres que sí se pueden afirmar por su clave —secundaria,
 * bachillerato y equivalente a bachillerato—; el resto queda en null y lo
 * decide la escuela desde la pantalla de facturación. `Técnico Superior
 * Universitario` NO se mapea a «Profesional técnico» aunque se parezcan: aquél
 * es educación superior y éste es medio superior, y confundirlos sería
 * declararle al SAT un nivel que no es.
 *
 * ── `conceptos_pago.deducible_iedu` ────────────────────────────────────────
 * El complemento ampara enseñanza. Una credencial de reposición, un examen
 * extraordinario o el transporte no lo son, y meterlos dentro haría que el
 * comprobante declarara como colegiatura algo que no lo es. Qué concepto es
 * enseñanza lo decide la escuela: nace apagado y se enciende en el catálogo.
 *
 * ── `factura_iedu`: tabla aparte, y CONGELADA ──────────────────────────────
 * Una fila por factura, o ninguna. Aparte de `facturas` porque su ausencia
 * tiene significado —«esta factura no lleva complemento» es un hecho, no
 * cuatro nulos entre treinta y cinco columnas— y porque así «cuáles llevan
 * IEDU» es una unión y no un barrido de nulos.
 *
 * Los cuatro datos se COPIAN, igual que el emisor y el receptor: si mañana se
 * corrige el RVOE del plan o la CURP de la alumna, el comprobante ya timbrado
 * tiene que seguir diciendo lo que se timbró.
 *
 * ── `facturas.iedu_motivo` ─────────────────────────────────────────────────
 * Se escribe SÓLO cuando la factura ampara enseñanza y aun así el complemento
 * no pudo viajar (falta la CURP, falta el RVOE, el nivel no está mapeado). Es
 * la pregunta que llega seis meses después —«¿por qué la mía no lo trae?»— y
 * derivarla al mirarla mentiría en cuanto alguien capture el dato que faltaba:
 * diría «no le falta nada» sobre una factura que salió sin complemento.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Se comprueba PIEZA por pieza y no por bloque: un reintento tras un
        // fallo parcial no debe saltarse lo que sí quedó pendiente. Es la
        // lección que dejó el CHECK de movilidad.
        if (! Schema::hasColumn('niveles_estudio', 'nivel_iedu')) {
            Schema::table('niveles_estudio', function (Blueprint $tabla) {
                $tabla->string('nivel_iedu', 40)->nullable()->after('clave_sat');
            });
        }

        if (! Schema::hasColumn('conceptos_pago', 'deducible_iedu')) {
            Schema::table('conceptos_pago', function (Blueprint $tabla) {
                $tabla->boolean('deducible_iedu')->default(false)->after('objeto_impuesto');
            });
        }

        if (! Schema::hasColumn('facturas', 'iedu_motivo')) {
            Schema::table('facturas', function (Blueprint $tabla) {
                $tabla->string('iedu_motivo', 255)->nullable()->after('motivo_cancelacion');
            });
        }

        if (! Schema::hasTable('factura_iedu')) {
            Schema::create('factura_iedu', function (Blueprint $tabla) {
                $tabla->id();
                // Único: el complemento es uno por comprobante. Y en cascada,
                // porque un borrador se puede borrar y su complemento no tiene
                // vida propia.
                $tabla->foreignId('factura_id')->unique()->constrained('facturas')->cascadeOnDelete();
                $tabla->string('nombre_alumno', 200);
                $tabla->string('curp', 18);
                $tabla->string('nivel_educativo', 40);
                $tabla->string('aut_rvoe', 60);
                $tabla->auditoria();
            });
        }

        // La semilla del mapeo. Sólo lo que la CLAVE permite afirmar; se
        // respeta lo ya capturado (`whereNull`) para que un reintento no pise
        // lo que alguien haya decidido entre una corrida y otra.
        $semilla = [
            'secundaria' => 'Secundaria',
            'bachillerato' => 'Bachillerato o su equivalente',
            'equivalente_bachillerato' => 'Bachillerato o su equivalente',
        ];

        foreach ($semilla as $clave => $nivel) {
            DB::table('niveles_estudio')
                ->where('clave', $clave)
                ->whereNull('nivel_iedu')
                ->update(['nivel_iedu' => $nivel]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_iedu');

        foreach ([
            ['facturas', 'iedu_motivo'],
            ['conceptos_pago', 'deducible_iedu'],
            ['niveles_estudio', 'nivel_iedu'],
        ] as [$tabla, $columna]) {
            if (Schema::hasColumn($tabla, $columna)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn($columna));
            }
        }
    }
};
