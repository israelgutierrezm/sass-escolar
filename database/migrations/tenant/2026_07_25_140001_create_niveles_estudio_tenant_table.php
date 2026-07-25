<?php

declare(strict_types=1);

use App\Models\Landlord\NivelEstudio as NivelLandlord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel de estudios pasa de LANDLORD (compartido) a TENANT (por escuela).
 *
 * El cliente lo decidió así: cada escuela oferta niveles distintos —un
 * bachillerato no tiene doctorados— y debe poder administrar los suyos desde
 * Configuración / Catálogos. Entidad e Identidad Federativa se quedan globales
 * porque son claves oficiales que no deben diverger; el nivel no es oficial en
 * ese sentido, es oferta.
 *
 * CLAVE, igual que en el desdoble federativo: la tabla nueva se puebla con los
 * MISMOS ids que traía la landlord. Las carreras ya guardan `nivel_estudios_id`
 * en ese rango; al repuntar el modelo `Carrera` al catálogo tenant, los ids
 * siguen cuadrando y NO hay que migrar dato alguno de las carreras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niveles_estudio', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('clave', 50);
            $tabla->string('nombre');
            // `orden` define la progresión (bachillerato < licenciatura <
            // maestría…) y con eso se listan; no se alfabetizan.
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->auditoria();

            $tabla->unique(['clave', 'deleted_at']);
        });

        // Se copian los niveles de la landlord heredando su id. Si por lo que
        // fuera la central no respondiera, se cae a la lista estándar de la SEP
        // con los mismos ids, para que el catálogo nunca nazca vacío.
        $niveles = NivelLandlord::query()->orderBy('id')->get(['id', 'clave', 'nombre', 'orden']);

        if ($niveles->isEmpty()) {
            $niveles = collect([
                ['id' => 1, 'clave' => 'bachillerato', 'nombre' => 'Bachillerato', 'orden' => 1],
                ['id' => 2, 'clave' => 'tecnico_superior', 'nombre' => 'Técnico Superior Universitario', 'orden' => 2],
                ['id' => 3, 'clave' => 'licenciatura', 'nombre' => 'Licenciatura', 'orden' => 3],
                ['id' => 4, 'clave' => 'especialidad', 'nombre' => 'Especialidad', 'orden' => 4],
                ['id' => 5, 'clave' => 'maestria', 'nombre' => 'Maestría', 'orden' => 5],
                ['id' => 6, 'clave' => 'doctorado', 'nombre' => 'Doctorado', 'orden' => 6],
                ['id' => 7, 'clave' => 'diplomado', 'nombre' => 'Diplomado', 'orden' => 7],
            ])->map(fn ($n) => (object) $n);
        }

        foreach ($niveles as $nivel) {
            DB::table('niveles_estudio')->insert([
                'id' => $nivel->id,
                'clave' => $nivel->clave,
                'nombre' => $nivel->nombre,
                'orden' => $nivel->orden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('niveles_estudio');
    }
};
