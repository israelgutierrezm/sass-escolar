<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `plan_materias.tipo` deja de capturarse: se quitó el selector «Tipo en el
 * plan» de la malla.
 *
 * Había DOS tipos de asignatura conviviendo y era una fuente de confusión: el
 * del catálogo (`asignaturas.tipo_asignatura_id` → OBLIGATORIA / OPTATIVA /
 * ADICIONAL / COMPLEMENTARIA, que es el que viaja al certificado SEP) y esta
 * columna de texto, con su propio vocabulario en minúsculas y un
 * `tronco_comun` sin equivalente oficial. Manda el del catálogo.
 *
 * La columna se vuelve NULLABLE en vez de eliminarse: los valores capturados se
 * conservan por si alguna escuela distinguía el papel de una materia dentro de
 * un plan concreto. Si se confirma que nadie los necesita, se puede borrar
 * después sin prisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_materias', function (Blueprint $tabla) {
            $tabla->string('tipo', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Las filas nuevas nacen sin tipo; se les repone el valor por defecto
        // antes de volver a exigirlo, porque NOT NULL no admite los nulos.
        DB::table('plan_materias')->whereNull('tipo')->update(['tipo' => 'obligatoria']);

        Schema::table('plan_materias', function (Blueprint $tabla) {
            $tabla->string('tipo', 30)->nullable(false)->change();
        });
    }
};
