<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada área lleva un color hexadecimal propio: es el que pinta la materia en la
 * vista de cuadrícula de la malla, para leer de un vistazo a qué academia
 * pertenece cada asignatura. Si al alta no se asigna, se genera un tono pastel
 * aleatorio (ver CatalogoAcademicoController::colorPastelAleatorio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $tabla) {
            $tabla->string('color', 7)->nullable()->after('nombre');
        });

        // Las áreas que ya existen nacen sin color; se les asigna un pastel
        // determinista por id para que la cuadrícula no salga toda gris.
        foreach (DB::table('areas')->whereNull('color')->pluck('id') as $id) {
            $mezcla = fn (int $semilla) => (int) round(((($semilla * 2654435761) % 256 + 256) % 256 + 255) / 2);
            $color = sprintf('#%02X%02X%02X', $mezcla($id * 3 + 1), $mezcla($id * 7 + 2), $mezcla($id * 11 + 3));
            DB::table('areas')->where('id', $id)->update(['color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $tabla) {
            $tabla->dropColumn('color');
        });
    }
};
