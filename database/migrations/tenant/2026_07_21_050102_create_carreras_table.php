<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * carreras (TENANT) — con campos SEP. `nivel_estudios_id` es ref lógica a
 * niveles_estudio (landlord), sin FK real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carreras', function (Blueprint $table) {
            $table->id();
            $table->string('identificador', 50); // ID estable entre migraciones (academyx)
            $table->string('clave', 50);
            $table->string('nombre');
            $table->unsignedBigInteger('nivel_estudios_id'); // landlord, sin FK
            $table->string('clave_sat', 15)->nullable(); // para CFDI de colegiaturas
            $table->text('objetivo')->nullable();
            $table->string('imagen_url')->nullable();
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carreras');
    }
};
