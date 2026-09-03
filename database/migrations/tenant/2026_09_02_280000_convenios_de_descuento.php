<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convenios de descuento: el acuerdo con una empresa, un sindicato o una
 * dependencia.
 *
 * ── Lo que hoy no se puede decir ───────────────────────────────────────────
 * Un descuento de CAMPAÑA aplica a toda la escuela: `CalculadorCargo` los
 * consulta sin acotar a nadie. Así que «20 % para los hijos de los empleados de
 * la empresa X» no se puede expresar — o se le da a todo el mundo, o no se da.
 *
 * ── Y por qué esto NO es una tabla de descuentos nueva ─────────────────────
 * Porque el motor de BECAS ya hace exactamente lo que hace falta: un porcentaje
 * o un monto, sobre ciertos conceptos, otorgado A UNA MATRÍCULA, con su
 * bitácora, su autorización, su renovación y su presupuesto. Un segundo motor
 * con las mismas reglas divergiría —es lo que le pasó a `vinculos_familiares`
 * frente a `tutores_alumno`— y obligaría a preguntar en dos sitios cuánto
 * descuenta alguien.
 *
 * Lo que el convenio agrega es lo que la beca NO sabe decir: quién es la
 * contraparte, desde cuándo y hasta cuándo vale el acuerdo, dónde está el papel
 * firmado, y —lo importante— que **al terminar el convenio se acaban TODAS sus
 * becas a la vez**. Hoy cada otorgamiento tiene sus propias fechas y nada las
 * cierra juntas.
 *
 * ── La contraparte NO se toma del padrón de la bolsa ───────────────────────
 * `empresas` es el padrón de EMPLEADORES de la bolsa de trabajo, con su propia
 * situación —«vetada»— que no debe apagar un convenio de descuento, y vive
 * detrás de un módulo que se puede apagar. Además una contraparte puede ser un
 * sindicato o una dependencia, que no son empleadores de ese padrón. Se guarda
 * aquí, con sus datos de contacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('convenios_descuento')) {
            Schema::create('convenios_descuento', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('nombre', 150);
                // Con quién se firmó. Ver la nota de arriba.
                $tabla->string('contraparte', 180);
                $tabla->string('rfc', 13)->nullable();
                $tabla->string('contacto', 160)->nullable();
                $tabla->string('correo', 160)->nullable();
                $tabla->string('telefono', 30)->nullable();
                $tabla->date('vigente_desde');
                // Obligatorio: un convenio sin fin no se acaba nunca, y el
                // descuento se queda vivo después de que la relación terminó.
                $tabla->date('vigente_hasta');
                // El papel firmado, en el disco privado.
                $tabla->string('documento_ruta', 255)->nullable();
                $tabla->string('documento_nombre', 160)->nullable();
                $tabla->string('notas', 500)->nullable();
                $tabla->string('estatus', 20)->default('vigente');
                $tabla->timestamp('terminado_en')->nullable();
                $tabla->string('motivo_termino', 255)->nullable();
                $tabla->auditoria();

                $tabla->index(['estatus', 'vigente_hasta']);
            });
        }

        if (! Schema::hasColumn('becas', 'convenio_descuento_id')) {
            Schema::table('becas', function (Blueprint $tabla) {
                /*
                 * Puesto: esta beca son los TÉRMINOS de ese convenio. Nulo en
                 * las becas normales, que es la inmensa mayoría — por eso va
                 * aquí y no en una tabla aparte que habría que unir siempre.
                 */
                $tabla->foreignId('convenio_descuento_id')->nullable()->after('patrocinador_id')
                    ->constrained('convenios_descuento');
            });
        }

        if (! Schema::hasColumn('becas_alumno', 'justificacion')) {
            Schema::table('becas_alumno', function (Blueprint $tabla) {
                /*
                 * Por qué ESTA persona califica: «empleado 4471, María Pérez,
                 * madre». Sin ella, dentro de un año nadie puede explicar por
                 * qué esta familia tiene el descuento de esa empresa — y es lo
                 * primero que se pregunta cuando el convenio se renueva.
                 */
                $tabla->string('justificacion', 255)->nullable()->after('motivo');
            });
        }

        /*
         * Y se retira `descuentos.tipo = 'manual'`.
         *
         * La pantalla lo ofrecía y la validación lo aceptaba, pero
         * `CalculadorCargo` sólo lee `campana` y `pago_anticipado`: un descuento
         * «manual» no descontaba NADA. Es la misma familia que `ver-personas` y
         * `crear-personas` — una opción que se elige creyendo que hace algo.
         *
         * Se convierten a `campana` los que hubiera, en vez de borrarlos: la
         * escuela los capturó a propósito y decidir que sobran no es de una
         * migración. Como campaña por fin descuentan, que es lo que quien los
         * creó esperaba.
         */
        $convertidos = DB::table('descuentos')->where('tipo', 'manual')->update(['tipo' => 'campana']);

        if ($convertidos > 0) {
            echo "  Se convirtieron {$convertidos} descuento(s) «manual» a «campaña»: el tipo manual no descontaba nada.\n";
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('becas_alumno', 'justificacion')) {
            Schema::table('becas_alumno', fn (Blueprint $t) => $t->dropColumn('justificacion'));
        }

        if (Schema::hasColumn('becas', 'convenio_descuento_id')) {
            Schema::table('becas', function (Blueprint $tabla) {
                $tabla->dropForeign(['convenio_descuento_id']);
                $tabla->dropColumn('convenio_descuento_id');
            });
        }

        Schema::dropIfExists('convenios_descuento');
    }
};
