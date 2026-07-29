<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tipo de campus pasa a OPCIONAL: no toda escuela clasifica sus planteles y
 * forzarlo era un dato de relleno. La FK se mantiene (sigue apuntando a
 * tipos_campus cuando se captura), solo se permite nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campus', function (Blueprint $table) {
            $table->unsignedBigInteger('tipo_campus_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('campus', function (Blueprint $table) {
            $table->unsignedBigInteger('tipo_campus_id')->nullable(false)->change();
        });
    }
};
