<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La SITUACIÓN del aspirante se retira por completo.
 *
 * Es el cierre de lo que empezó `la_situacion_deja_de_duplicar_al_embudo`, que
 * le quitó «En proceso» y «Aceptado» por ser puntos del recorrido disfrazados de
 * desenlace. Quedaban tres valores y sólo dos informaban, así que el campo
 * entero se va.
 *
 * ── A dónde van los dos que informaban ────────────────────────────────────
 *
 * **INSCRITO se DERIVA**, no se guarda: es tener `matricula_oferta` para la
 * oferta de interés. Es MÁS CIERTO que el campo —hoy se puede tener situación
 * «Inscrito» sin matrícula y nada se queja— y es exactamente la pareja
 * (persona, oferta) que `ConvertidorAspirante` ya comprueba antes de convertir.
 *
 * Y se deriva por la OFERTA y no por «tiene alguna matrícula»: quien ya estudia
 * una carrera y se postula a una segunda seguiría siendo un prospecto abierto
 * para esa segunda, y marcarlo como inscrito lo sacaría del embudo desde el
 * primer día.
 *
 * **RECHAZADO se vuelve `descartado_en` + `motivo_descarte`.** Un descarte tiene
 * FECHA y RAZÓN, y una fila de catálogo no puede darlas: «Rechazado» no dice ni
 * cuándo ni por qué, que es justo lo que se pregunta cuando alguien revisa por
 * qué se cayó un prospecto.
 *
 * ── El respaldo de los que estaban rechazados ─────────────────────────────
 * Se les pone `descartado_en` con su `updated_at`, que es la mejor evidencia que
 * hay de cuándo pasó. **El motivo se queda en NULL y NO se inventa**: nunca
 * hubo uno guardado, y escribir «Rechazado» ahí sería fabricar una razón que
 * nadie dio.
 *
 * ── Y por eso se hace en este orden ───────────────────────────────────────
 * Primero las columnas nuevas, luego el respaldo leyendo la vieja, y sólo
 * después el DROP. Al revés, el respaldo no tendría de dónde leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->columnasNuevas();
        $this->respaldarLosRechazados();
        $this->retirarLaSituacion();
    }

    /**
     * La vuelta atrás recrea el catálogo VACÍO y la columna en NULL.
     *
     * No se reconstruyen los valores: la situación de cada aspirante ya no
     * existe en ningún sitio del que sacarla, y llenar la columna con
     * «Prospecto» para todos diría que nadie se descartó nunca. Un `down` que
     * miente es peor que uno que deja el hueco a la vista.
     */
    public function down(): void
    {
        if (! Schema::hasTable('situaciones_aspirante')) {
            Schema::create('situaciones_aspirante', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);
                $t->auditoria();
            });
        }

        if (! Schema::hasColumn('aspirantes', 'situacion_id')) {
            Schema::table('aspirantes', fn (Blueprint $t) => $t
                ->foreignId('situacion_id')->nullable()->after('clave_aspirante')
                ->constrained('situaciones_aspirante'));
        }

        foreach (['descartado_en', 'motivo_descarte'] as $columna) {
            if (Schema::hasColumn('aspirantes', $columna)) {
                Schema::table('aspirantes', fn (Blueprint $t) => $t->dropColumn($columna));
            }
        }
    }

    private function columnasNuevas(): void
    {
        if (! Schema::hasColumn('aspirantes', 'descartado_en')) {
            Schema::table('aspirantes', fn (Blueprint $t) => $t
                ->timestamp('descartado_en')->nullable()->after('validado_admin'));
        }

        if (! Schema::hasColumn('aspirantes', 'motivo_descarte')) {
            Schema::table('aspirantes', fn (Blueprint $t) => $t
                ->string('motivo_descarte', 255)->nullable()->after('descartado_en'));
        }
    }

    private function respaldarLosRechazados(): void
    {
        // Ya no hay de dónde leer: o se corrió antes, o la columna nunca estuvo.
        if (! Schema::hasColumn('aspirantes', 'situacion_id') || ! Schema::hasTable('situaciones_aspirante')) {
            return;
        }

        $rechazado = DB::table('situaciones_aspirante')->where('clave', 'rechazado')->value('id');

        if ($rechazado === null) {
            return;
        }

        /*
         * `descartado_en` del `updated_at`, que es lo más cercano a cuándo se le
         * marcó. El motivo se queda vacío a propósito: no había ninguno y
         * escribir «Rechazado» sería inventar una razón.
         */
        DB::table('aspirantes')
            ->where('situacion_id', $rechazado)
            ->whereNull('descartado_en')
            ->update(['descartado_en' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    private function retirarLaSituacion(): void
    {
        if (Schema::hasColumn('aspirantes', 'situacion_id')) {
            Schema::table('aspirantes', function (Blueprint $t) {
                // La foránea PRIMERO: soltar la columna con la restricción
                // puesta revienta.
                $t->dropForeign(['situacion_id']);
                $t->dropColumn('situacion_id');
            });
        }

        Schema::dropIfExists('situaciones_aspirante');
    }
};
