<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * paises (LANDLORD) — catálogo universal, compartido por todas las escuelas.
 * Read-only para los tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paises', function (Blueprint $table) {
            $table->id();
            $table->string('clave_iso', 3)->unique(); // ISO 3166-1 alfa-3
            $table->string('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paises');
    }
};
