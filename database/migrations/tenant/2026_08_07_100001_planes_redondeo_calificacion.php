<?php

declare(strict_types=1);

use App\Enums\ModoRedondeo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se redondea lo que no cabe en la precisión del plan.
 *
 * El plan ya dice con cuántos decimales se califica, pero no qué hacer con un
 * promedio de 8.5 cuando la escala es de enteros. Unas escuelas lo suben a 9,
 * otras exigen 8.6 para subir y otras nunca suben. Decide quién se titula con
 * mención y quién conserva una beca, así que se configura.
 *
 * ── Por qué medio-arriba por omisión ───────────────────────────────────────
 * Es lo que casi todo el mundo entiende por «redondear» y lo que el sistema ya
 * hacía —cada pantalla llamaba a `round()`, que es medio-arriba—, así que nadie
 * ve cambiar un promedio por una migración que no pidió. Quien quiera otra cosa
 * la configura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->string('redondeo_calificacion', 20)
                ->default(ModoRedondeo::MEDIO_ARRIBA->value)
                ->after('decimales_calificacion');
        });
    }

    public function down(): void
    {
        Schema::table('planes_estudio', function (Blueprint $table) {
            $table->dropColumn('redondeo_calificacion');
        });
    }
};
