<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El nivel de estudios del ciclo pasa de UNO a VARIOS.
 *
 * Se pidió que el ciclo pueda acotarse a varios niveles a la vez, igual que ya
 * hace con los campus —un ciclo de «media superior» que cubra bachillerato y
 * bachillerato tecnológico, por ejemplo—. Deja de ser una columna y se vuelve
 * pivote `ciclo_nivel`, espejo de `ciclo_campus`.
 *
 * El valor único que cada ciclo tuviera se conserva: se copia al pivote antes
 * de tirar la columna, así ningún acotamiento existente se pierde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciclo_nivel', function (Blueprint $tabla) {
            $tabla->foreignId('ciclo_id')->constrained('ciclos')->cascadeOnDelete();
            $tabla->foreignId('nivel_estudios_id')->constrained('niveles_estudio')->cascadeOnDelete();
            $tabla->timestamps();

            $tabla->primary(['ciclo_id', 'nivel_estudios_id']);
        });

        // Lo que ya estaba acotado a un nivel se muda al pivote intacto.
        foreach (DB::table('ciclos')->whereNotNull('nivel_estudios_id')->get(['id', 'nivel_estudios_id']) as $ciclo) {
            DB::table('ciclo_nivel')->insert([
                'ciclo_id' => $ciclo->id,
                'nivel_estudios_id' => $ciclo->nivel_estudios_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('ciclos', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('nivel_estudios_id');
        });
    }

    public function down(): void
    {
        Schema::table('ciclos', function (Blueprint $tabla) {
            $tabla->foreignId('nivel_estudios_id')->nullable()->after('numero_periodo')
                ->constrained('niveles_estudio')->nullOnDelete();
        });

        // Al volver a UNO se conserva el primero que tuviera cada ciclo.
        foreach (DB::table('ciclo_nivel')->get() as $fila) {
            DB::table('ciclos')->where('id', $fila->ciclo_id)
                ->whereNull('nivel_estudios_id')
                ->update(['nivel_estudios_id' => $fila->nivel_estudios_id]);
        }

        Schema::dropIfExists('ciclo_nivel');
    }
};
