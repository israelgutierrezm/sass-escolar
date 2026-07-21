<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * promociones (TENANT) — descuentos de admisión asignables a aspirantes
 * (del legacy cat_promocion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promociones', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50);
            $table->string('nombre');
            $table->string('descripcion', 255)->nullable();
            $table->integer('descuento'); // porcentaje o monto
            $table->date('vigencia');
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promociones');
    }
};
