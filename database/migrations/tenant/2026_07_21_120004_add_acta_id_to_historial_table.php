<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga el kárdex con el acta de la que salió.
 *
 * `acta_folio` (spec) se queda: es la cadena que se imprime y por la que
 * pregunta control escolar. `acta_id` es la FK real, la que permite navegar
 * del renglón del kárdex al acta completa y a su cadena de correcciones.
 *
 * Nullable porque no todo lo que entra al kárdex viene de un acta: una
 * revalidación o una equivalencia se asientan por dictamen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historial', function (Blueprint $table) {
            $table->foreignId('acta_id')->nullable()->after('acta_folio')->constrained('actas');
        });
    }

    public function down(): void
    {
        Schema::table('historial', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acta_id');
        });
    }
};
