<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desdobla el catálogo federativo en DOS, ambos en la landlord (compartidos).
 *
 * Hasta ahora había una sola tabla `entidades_federativas` que servía a la vez
 * para LUGARES (dónde está un campus) y para PERSONAS (dónde nació alguien), y
 * su registro 33 decía «Nacido en el Extranjero» —redacción de persona— aunque
 * también etiquetara el domicilio de un plantel. El cliente pidió separarlos
 * porque son contextos distintos y su redacción difiere:
 *
 *  - `entidades_federativas` (LUGARES): el 33 pasa a «Extranjero».
 *  - `identidades_federativas` (PERSONAS, tabla nueva): el 33 es «Nacido en el
 *    extranjero».
 *
 * CLAVE: la tabla nueva se puebla con los MISMOS ids (1..33) que la vieja. Las
 * personas ya guardan `entidad_nacimiento_id` en ese rango; al repuntarlas al
 * catálogo de identidad, los ids siguen cuadrando y NO hace falta migrar dato
 * alguno de las personas. Las claves de dos letras (AS, BC… NE) son idénticas
 * en ambos, así que la lectura de la CURP sigue funcionando igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identidades_federativas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('pais_id')->constrained('paises');
            $tabla->string('clave', 5);
            $tabla->string('nombre');

            $tabla->unique(['pais_id', 'clave']);
        });

        // Se copia tal cual desde el catálogo existente para heredar los ids.
        // El único que cambia de texto es el 33: aquí es de persona.
        $filas = DB::table('entidades_federativas')->orderBy('id')->get();

        foreach ($filas as $fila) {
            DB::table('identidades_federativas')->insert([
                'id' => $fila->id,
                'pais_id' => $fila->pais_id,
                'clave' => $fila->clave,
                'nombre' => $fila->clave === 'NE' ? 'Nacido en el extranjero' : $fila->nombre,
            ]);
        }

        // Y el catálogo de LUGARES se corrige: un campus no «nace», está.
        DB::table('entidades_federativas')->where('clave', 'NE')->update(['nombre' => 'Extranjero']);
    }

    public function down(): void
    {
        DB::table('entidades_federativas')->where('clave', 'NE')->update(['nombre' => 'Nacido en el Extranjero']);
        Schema::dropIfExists('identidades_federativas');
    }
};
