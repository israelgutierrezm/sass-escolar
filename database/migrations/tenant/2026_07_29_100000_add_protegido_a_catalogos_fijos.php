<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `protegido` para catálogos de valores fijos (niveles de estudio y tipos de
 * periodo): los oficiales no se editan ni se eliminan desde la administración.
 * Nullable-con-default para no tocar los datos ya sembrados en tenants
 * existentes (que se quedan como estaban; los nuevos nacen con el set fijo).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['niveles_estudio', 'tipos_periodo'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->boolean('protegido')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['niveles_estudio', 'tipos_periodo'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('protegido');
            });
        }
    }
};
