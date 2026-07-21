<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * aulas (TENANT-CONFIG) — espacios físicos, por campus. A diferencia de los
 * demás catálogos del módulo no es una lista plana: cada aula pertenece a un
 * campus y su capacidad alimenta la validación de cupo y el motor de horarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained('campus')->cascadeOnDelete();
            $table->string('clave', 50);
            $table->string('nombre', 150);
            $table->integer('capacidad')->nullable();
            $table->auditoria();

            $table->unique(['campus_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aulas');
    }
};
