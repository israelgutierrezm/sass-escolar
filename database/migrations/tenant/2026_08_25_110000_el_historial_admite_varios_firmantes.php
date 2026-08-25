<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién firma el historial: de UNO a varios.
 *
 * ── El hueco ─────────────────────────────────────────────────────────────
 * `disenos_historial` tenía `responsable_nombre`, `responsable_cargo` y
 * `firma_imagen`: un solo firmante. Una escuela que exige la rúbrica del
 * director Y la de control escolar —que es lo normal en un documento
 * escolar— no lo podía expresar, y quien lo necesitaba acababa metiendo dos
 * nombres en el mismo campo.
 *
 * ── Tabla y no un JSON ───────────────────────────────────────────────────
 * Cada firmante trae su IMAGEN de firma, que es un archivo en el disco
 * privado con su propio ciclo de vida —se sube, se reemplaza, se borra—. En un
 * JSON eso significa administrar rutas a mano dentro de un arreglo y quedarse
 * con archivos huérfanos cuando alguien quita un firmante del medio.
 *
 * ── Se MIGRA lo que había y se retiran las columnas viejas ───────────────
 * El responsable que ya estaba configurado pasa a ser el primer firmante, con
 * su firma. Y las tres columnas se van: dejarlas convertiría «quién firma» en
 * dos sitios donde mirar, que es exactamente el defecto que este cambio viene
 * a quitar. `sello_imagen` NO se toca: el sello es de la escuela, no de una
 * persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('firmantes_historial')) {
            Schema::create('firmantes_historial', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('diseno_id')->constrained('disenos_historial')->cascadeOnDelete();
                $tabla->string('nombre', 120);
                $tabla->string('cargo', 120)->nullable();
                // Ruta en el disco PRIVADO: una firma escaneada es media
                // falsificación de un historial.
                $tabla->string('firma_imagen')->nullable();
                $tabla->unsignedSmallInteger('orden')->default(0);
                $tabla->auditoria();

                $tabla->index(['diseno_id', 'orden']);
            });
        }

        // El responsable que ya existía pasa a ser el primer firmante. Se
        // comprueba la columna porque un reintento tras un fallo parcial no
        // debe chocar contra su propio trabajo.
        if (Schema::hasColumn('disenos_historial', 'responsable_nombre')) {
            $existentes = DB::table('disenos_historial')
                ->whereNull('deleted_at')
                ->whereNotNull('responsable_nombre')
                ->where('responsable_nombre', '<>', '')
                ->get(['id', 'responsable_nombre', 'responsable_cargo', 'firma_imagen']);

            foreach ($existentes as $diseno) {
                $yaEsta = DB::table('firmantes_historial')
                    ->where('diseno_id', $diseno->id)
                    ->exists();

                if ($yaEsta) {
                    continue;
                }

                DB::table('firmantes_historial')->insert([
                    'diseno_id' => $diseno->id,
                    'nombre' => $diseno->responsable_nombre,
                    'cargo' => $diseno->responsable_cargo,
                    'firma_imagen' => $diseno->firma_imagen,
                    'orden' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('disenos_historial', function (Blueprint $tabla) {
                $tabla->dropColumn(['responsable_nombre', 'responsable_cargo', 'firma_imagen']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('disenos_historial', 'responsable_nombre')) {
            Schema::table('disenos_historial', function (Blueprint $tabla) {
                $tabla->string('responsable_nombre', 120)->nullable();
                $tabla->string('responsable_cargo', 120)->nullable();
                $tabla->string('firma_imagen')->nullable();
            });

            // Se devuelve el PRIMER firmante de cada diseño; los demás se
            // pierden, que es lo que significa volver a un solo responsable.
            foreach (DB::table('firmantes_historial')->orderBy('orden')->get() as $firmante) {
                DB::table('disenos_historial')
                    ->where('id', $firmante->diseno_id)
                    ->whereNull('responsable_nombre')
                    ->update([
                        'responsable_nombre' => $firmante->nombre,
                        'responsable_cargo' => $firmante->cargo,
                        'firma_imagen' => $firmante->firma_imagen,
                    ]);
            }
        }

        Schema::dropIfExists('firmantes_historial');
    }
};
