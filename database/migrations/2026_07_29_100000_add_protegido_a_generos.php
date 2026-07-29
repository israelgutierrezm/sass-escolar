<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `protegido` en el catálogo CENTRAL de géneros: los dos oficiales (Mujer /
 * Hombre) no se editan ni se eliminan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generos', function (Blueprint $table) {
            $table->boolean('protegido')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('generos', function (Blueprint $table) {
            $table->dropColumn('protegido');
        });
    }
};
