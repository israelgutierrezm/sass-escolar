<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un campus pertenece a una INSTITUCIÓN.
 *
 * El vínculo es informativo —no condiciona la oferta ni los grupos—, pero es un
 * dato real: hay escuelas con varias razones sociales/instituciones y cada
 * plantel pertenece a una. Nullable porque no todo campus tendrá una asignada
 * de inmediato, y con `nullOnDelete` para que borrar una institución (cuando no
 * tiene campus, por la salvaguarda) nunca deje una FK colgando.
 *
 * Los campus que ya existen se enganchan a la institución sembrada por defecto:
 * así la preselección «si solo hay una» arranca coherente y ningún plantel
 * queda huérfano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campus', function (Blueprint $tabla) {
            $tabla->foreignId('institucion_id')->nullable()->after('nombre')
                ->constrained('instituciones')->nullOnDelete();
        });

        $principal = DB::table('instituciones')->orderBy('id')->value('id');

        if ($principal !== null) {
            DB::table('campus')->whereNull('institucion_id')->update(['institucion_id' => $principal]);
        }
    }

    public function down(): void
    {
        Schema::table('campus', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('institucion_id');
        });
    }
};
