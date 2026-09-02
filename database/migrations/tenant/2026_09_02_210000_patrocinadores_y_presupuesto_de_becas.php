<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién paga cada beca, y cuánto hay para repartir.
 *
 * ── El hueco ───────────────────────────────────────────────────────────────
 * El motor de becas está completo —porcentaje o monto, vigencia, condiciones,
 * renovación, suspensión— y le faltan las dos preguntas que hace la dirección
 * cuando llega la temporada: «¿cuánto llevamos becado?» y «¿de dónde sale ese
 * dinero?». Sin ellas, otorgar es una decisión a ciegas: cada beca por separado
 * parece razonable y el total no lo mira nadie hasta el corte del año.
 *
 * ── El patrocinador es de la BECA, no del alumno ───────────────────────────
 * Quien financia es una propiedad del programa —«Beca Fundación X» la paga la
 * Fundación X— y es así como se asigna el presupuesto: la fundación da tanto
 * este ciclo. Colgándolo del alumno, la misma beca la pagarían fuentes
 * distintas según a quién se le dio, y no habría bolsa que cuadrar.
 *
 * ── Y NO es un tercero al que se le factura ────────────────────────────────
 * Un patrocinador que PAGARA sería un titular más en `adeudos`, que hoy admite
 * exactamente dos —matrícula o aspirante, con un CHECK que lo obliga— y toda la
 * cartera está escrita sobre eso. Aquí el patrocinador dice de qué bolsa sale
 * el descuento, no a quién se le cobra. Facturarle es otro flujo y otra
 * decisión.
 *
 * ── Por eso `patrocinador_id` es NOT NULL, con «La escuela» sembrada ───────
 * La tentación es dejarlo nulo para las becas que absorbe la escuela. Con nulo,
 * el único de `presupuestos_beca` no sirve: MySQL considera distintos dos NULL,
 * así que se podrían crear dos presupuestos de la escuela para el mismo ciclo y
 * ninguna de las dos cifras sería la buena. Con una fila propia, la bolsa de la
 * escuela se administra igual que las demás.
 *
 * ── El ejercido se MIDE, no se estima ──────────────────────────────────────
 * `adeudo_ajustes` ya guarda un renglón por cada beca que movió un cargo, con
 * `origen_id` apuntando a la beca otorgada. O sea que «cuánto se ha becado de
 * verdad» es una suma sobre hechos, no una proyección. No se guarda ninguna
 * columna de «ejercido»: sería un total que hay que mantener sincronizado y que
 * el día que se desincronice nadie sabría contra qué comparar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('patrocinadores')) {
            Schema::create('patrocinadores', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('clave', 30)->unique();
                $tabla->string('nombre', 150);
                $tabla->string('contacto', 150)->nullable();
                $tabla->string('correo', 150)->nullable();
                $tabla->string('telefono', 30)->nullable();
                $tabla->text('notas')->nullable();
                $tabla->boolean('activo')->default(true);
                // La escuela no se borra ni se renombra: hay becas colgando de
                // ella y es el valor por omisión de toda beca nueva.
                $tabla->boolean('protegido')->default(false);
                $tabla->auditoria();
            });

            DB::table('patrocinadores')->insert([
                'clave' => 'escuela',
                'nombre' => 'La escuela',
                'notas' => 'La bolsa propia: las becas que la escuela absorbe de su propio ingreso.',
                'activo' => true,
                'protegido' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasColumn('becas', 'patrocinador_id')) {
            $escuela = (int) DB::table('patrocinadores')->where('clave', 'escuela')->value('id');

            Schema::table('becas', function (Blueprint $tabla) use ($escuela) {
                $tabla->foreignId('patrocinador_id')->default($escuela)
                    ->after('descripcion')
                    ->constrained('patrocinadores');
            });
        }

        if (! Schema::hasTable('presupuestos_beca')) {
            Schema::create('presupuestos_beca', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('patrocinador_id')->constrained('patrocinadores');
                $tabla->foreignId('ciclo_id')->constrained('ciclos');
                $tabla->decimal('monto', 14, 2);
                $tabla->text('notas')->nullable();
                $tabla->auditoria();

                // Una bolsa por patrocinador y ciclo. Con dos, ninguna de las
                // dos cifras sería la buena y el aviso de «te pasaste» dependería
                // de cuál se leyera primero.
                $tabla->unique(['patrocinador_id', 'ciclo_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('presupuestos_beca');

        if (Schema::hasColumn('becas', 'patrocinador_id')) {
            Schema::table('becas', function (Blueprint $tabla) {
                $tabla->dropForeign(['patrocinador_id']);
                $tabla->dropColumn('patrocinador_id');
            });
        }

        Schema::dropIfExists('patrocinadores');
    }
};
