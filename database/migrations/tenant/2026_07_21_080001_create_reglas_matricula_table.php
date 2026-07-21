<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reglas_matricula (TENANT-CONFIG) — cómo genera ESTA escuela sus matrículas.
 *
 * La matrícula la asigna un administrador como último paso antes de convertir
 * al aspirante en alumno; nunca antes. Cada escuela usa un formato distinto,
 * así que la regla es DATO, no código.
 *
 * Ámbito: se resuelve de lo más específico a lo más general —
 *   plan → carrera → global. La escuela normalmente define solo la global.
 *
 * `plantilla` usa tokens (se renderizan en App\Services\GeneradorMatricula):
 *   {AA}      año en 2 dígitos          {AAAA}   año en 4 dígitos
 *   {CARRERA} clave de la carrera       {PLAN}   clave del plan
 *   {CAMPUS}  clave del campus          {####}   consecutivo (padding = nº de #)
 * Ejemplos: "{AAAA}{CARRERA}{####}"  ·  "{AA}-{PLAN}-{#####}"
 *
 * `ambito_consecutivo` define la LLAVE del contador, es decir cada cuánto se
 * reinicia la numeración: global | anio | carrera | plan | carrera_anio | plan_anio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_matricula', function (Blueprint $table) {
            $table->id();
            $table->string('ambito', 20)->default('global'); // global / carrera / plan
            $table->unsignedBigInteger('ambito_id')->nullable(); // carrera_id o plan_id; NULL si global
            $table->string('plantilla', 100);
            $table->string('ambito_consecutivo', 20)->default('anio');
            $table->boolean('activo')->default(true);
            $table->auditoria();

            $table->unique(['ambito', 'ambito_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_matricula');
    }
};
