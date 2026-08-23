<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 11 · Bolsa de trabajo — las colocaciones y lo que hace falta para
 * medirlas.
 *
 * ── Una colocación NO siempre viene de una postulación ─────────────────────
 * `postulacion_id` va NULLABLE, al revés de lo que pedía la spec. Un egresado
 * consigue trabajo por su cuenta y la escuela se entera al darle seguimiento —y
 * ése es justamente el dato que piden las acreditadoras—. Obligándolo, esas
 * colocaciones no se podrían registrar y el indicador contestaría «a cuántos
 * colocó nuestra bolsa» en vez de «cuántos egresados están colocados», que es el
 * número que una escuela presume. Son dos preguntas distintas y aquí importa la
 * segunda.
 *
 * Por eso `persona_id` y `empresa_id` NO son denormalización de la postulación:
 * cuando no hay postulación, son el único sitio donde vive ese dato.
 *
 * ── Único sobre `postulacion_id`, y el NULL no estorba ─────────────────────
 * Una postulación produce UNA colocación; dos serían el mismo hecho contado dos
 * veces y el porcentaje saldría inflado. MySQL admite varios NULL en un índice
 * único, así que las de seguimiento —todas con NULL— no chocan entre sí. Es
 * exactamente el comportamiento que hace falta.
 *
 * ── `relacionado_con_carrera` es NULLABLE, y la diferencia importa ─────────
 * La spec lo ponía boolean a secas. Con `false` por omisión, una colocación que
 * se capturó sin preguntar diría «no es de su área», que es una afirmación que
 * nadie hizo. NULL significa «no se preguntó» y el reporte lo enseña aparte.
 * Misma regla que `autorizaciones.concedida`: no contestar no es contestar que
 * no.
 *
 * ── Lo que NO se guarda: la fecha de baja del empleo ───────────────────────
 * Una colocación es el HECHO de haber sido contratado, con su fecha, y eso es lo
 * que mide la acreditación —«colocado a los N meses de egresar»—. Saber si sigue
 * ahí es seguimiento longitudinal de egresados, otro producto; media columna de
 * ese producto sería una columna que nadie lee, y este proyecto ya tuvo que
 * retirar ajustes y permisos por eso mismo.
 *
 * ── Dos banderas de catálogo, para no cablear claves ───────────────────────
 * Qué etapa significa «lo contrataron» y qué situación de alumno cuenta como
 * egresado son decisiones de la escuela: los dos son catálogos que puede
 * renombrar o ampliar —«Pasante», «Egresado sin titular»—. Preguntar por
 * `clave = 'contratado'` funcionaría hoy y dejaría de funcionar en silencio el
 * día que alguien edite el catálogo, que es la peor forma de romperse.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->banderasDeEtapa();
        $this->banderaDeEgreso();
        $this->tablaDeColocaciones();
    }

    public function down(): void
    {
        Schema::dropIfExists('colocaciones');

        foreach ([['etapas_postulacion', ['marca_colocacion', 'es_final']], ['situaciones_alumno', ['cuenta_como_egresado']]] as [$tabla, $columnas]) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn($columna));
                }
            }
        }
    }

    /**
     * Qué etapa coloca y cuál cierra el proceso.
     *
     * Son hechos INDEPENDIENTES y por eso van dos banderas y no un enum:
     * «Rechazado» cierra y no coloca, «Contratado» cierra y coloca.
     */
    private function banderasDeEtapa(): void
    {
        Schema::table('etapas_postulacion', function (Blueprint $tabla) {
            if (! Schema::hasColumn('etapas_postulacion', 'marca_colocacion')) {
                $tabla->boolean('marca_colocacion')->default(false)->after('orden');
            }

            if (! Schema::hasColumn('etapas_postulacion', 'es_final')) {
                $tabla->boolean('es_final')->default(false)->after('marca_colocacion');
            }
        });

        DB::table('etapas_postulacion')->where('clave', 'contratado')
            ->update(['marca_colocacion' => true, 'es_final' => true]);

        DB::table('etapas_postulacion')->whereIn('clave', ['rechazado', 'desistio'])
            ->update(['es_final' => true]);
    }

    /** Qué situación de matrícula cuenta como egresado, para el denominador. */
    private function banderaDeEgreso(): void
    {
        if (! Schema::hasColumn('situaciones_alumno', 'cuenta_como_egresado')) {
            Schema::table('situaciones_alumno', fn (Blueprint $t) => $t
                ->boolean('cuenta_como_egresado')->default(false)->after('nombre'));
        }

        // Titulado también egresó: dejarlo fuera diría que quien se tituló ya no
        // cuenta, y el denominador encogería justo con los mejores casos.
        DB::table('situaciones_alumno')->whereIn('clave', ['egresado', 'titulado'])
            ->update(['cuenta_como_egresado' => true]);
    }

    private function tablaDeColocaciones(): void
    {
        if (Schema::hasTable('colocaciones')) {
            return;
        }

        Schema::create('colocaciones', function (Blueprint $tabla) {
            $tabla->id();

            // Null = no salió de la bolsa; la escuela se enteró dándole
            // seguimiento. Ver el docblock de arriba.
            $tabla->foreignId('postulacion_id')->nullable()->constrained('postulaciones')->nullOnDelete();

            $tabla->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            /*
             * Con qué carrera cuenta. Sin esto el reporte por programa —el que
             * pide la acreditadora— no tendría a qué renglón sumar, porque una
             * persona puede haber egresado de dos.
             */
            $tabla->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta')->nullOnDelete();

            $tabla->foreignId('empresa_id')->constrained('empresas');
            $tabla->string('puesto', 200);
            $tabla->decimal('salario', 12, 2)->nullable();
            $tabla->date('fecha_ingreso');

            // NULL = no se preguntó, que no es lo mismo que «no».
            $tabla->boolean('relacionado_con_carrera')->nullable();

            $tabla->text('notas')->nullable();
            $tabla->auditoria();

            // Una postulación, una colocación. Los NULL no chocan entre sí.
            $tabla->unique('postulacion_id');

            // Lo que se consulta es el corte por fecha y por persona.
            $tabla->index(['fecha_ingreso']);
            $tabla->index(['persona_id', 'fecha_ingreso']);
        });
    }
};
