<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tipos_antecedente_academico (TENANT-CONFIG) — bachillerato, licenciatura...
 * (de academyx_cyt). Lo consume el formulario "antecedente académico" y, más
 * adelante, la tabla antecedentes_academicos del módulo de titulación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_antecedente_academico', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_antecedente_academico');
    }
};
