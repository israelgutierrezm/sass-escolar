<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La oferta se delimita SOLO por carrera + plan + campus.
 *
 * La llave única era carrera+plan+campus+turno+modalidad. El cliente quiere que
 * dos ofertas del mismo programa en el mismo campus sean la MISMA oferta: el
 * turno sale de la oferta (se elimina la columna; el turno de los grupos es otro
 * y no se toca) y la modalidad pasa a ser un atributo OPCIONAL, fuera de la
 * llave. Antes de estrechar la llave se fusionan las ofertas que ahora colisionan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->deduplicar();

        // El índice nuevo se crea ANTES de tirar el viejo: el viejo respalda la
        // FK de carrera_id y MySQL no lo suelta si es el único que la sostiene.
        // El nuevo también empieza por carrera_id, así que toma el relevo.
        Schema::table('oferta', function (Blueprint $t) {
            $t->unique(['carrera_id', 'plan_id', 'campus_id']);
        });

        Schema::table('oferta', function (Blueprint $t) {
            $t->dropUnique('oferta_carrera_id_plan_id_campus_id_turno_id_modalidad_unique');
        });

        Schema::table('oferta', function (Blueprint $t) {
            $t->dropForeign(['turno_id']);
            $t->dropColumn('turno_id');
            $t->string('modalidad', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('oferta', function (Blueprint $t) {
            $t->foreignId('turno_id')->nullable()->after('campus_id')->constrained('turnos');
            $t->string('modalidad', 30)->default('presencial')->nullable(false)->change();
        });

        Schema::table('oferta', function (Blueprint $t) {
            $t->unique(['carrera_id', 'plan_id', 'campus_id', 'turno_id', 'modalidad']);
        });

        Schema::table('oferta', function (Blueprint $t) {
            $t->dropUnique('oferta_carrera_id_plan_id_campus_id_unique');
        });
    }

    /**
     * Fusiona las ofertas que colisionan en (carrera, plan, campus). Conserva la
     * que tenga matrículas (o la de menor id); reapunta el interés de aspirantes
     * a la conservada y borra las demás. Si dos con matrículas chocan, aborta:
     * eso lo resuelve una persona, no una migración a ciegas.
     */
    private function deduplicar(): void
    {
        $grupos = DB::table('oferta')
            ->selectRaw('carrera_id, plan_id, campus_id')
            ->groupBy('carrera_id', 'plan_id', 'campus_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($grupos as $g) {
            $ofertas = DB::table('oferta')
                ->where('carrera_id', $g->carrera_id)
                ->where('plan_id', $g->plan_id)
                ->where('campus_id', $g->campus_id)
                ->orderBy('id')
                ->get();

            $conMatricula = $ofertas->filter(
                fn ($o) => DB::table('matricula_oferta')->where('oferta_id', $o->id)->exists()
            );

            if ($conMatricula->count() > 1) {
                throw new RuntimeException(
                    "Varias ofertas con matrículas colisionan en carrera {$g->carrera_id}/plan {$g->plan_id}/campus {$g->campus_id}. Fusiónalas a mano antes de migrar."
                );
            }

            $conservar = $conMatricula->first()->id ?? $ofertas->first()->id;
            $eliminar = $ofertas->pluck('id')->reject(fn ($id) => $id === $conservar)->all();

            if ($eliminar !== []) {
                DB::table('aspirantes')->whereIn('oferta_interes_id', $eliminar)->update(['oferta_interes_id' => $conservar]);
                DB::table('oferta')->whereIn('id', $eliminar)->delete();
            }
        }
    }
};
