<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * seriacion (TENANT) — el DAG de prerequisitos. Relación reflexiva sobre
 * plan_materias: una materia puede requerir varias. Tipada (cursada vs
 * aprobada) o por mínimo de créditos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seriacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_materia_id')->constrained('plan_materias');
            $table->foreignId('requiere_plan_materia_id')->nullable()->constrained('plan_materias');
            $table->string('tipo', 20); // cursada / aprobada
            $table->float('minimo_creditos')->nullable(); // alternativa: "requiere X créditos"
            $table->auditoria();

            $table->unique(['plan_materia_id', 'requiere_plan_materia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seriacion');
    }
};
