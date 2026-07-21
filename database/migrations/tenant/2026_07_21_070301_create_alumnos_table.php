<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * alumnos (TENANT) — rol materializado. Atributos propios del rol alumno; NO
 * duplica datos de persona. PK = persona_id (1:1 con persona en su faceta de
 * alumno). El detalle por oferta vive en matricula_oferta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alumnos', function (Blueprint $table) {
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('clave_alumno', 50)->nullable();
            $table->string('cedula_profesional', 30)->nullable(); // cuando ya titulado
            $table->foreignId('situacion_id')->constrained('situaciones_alumno');
            $table->auditoria();

            $table->primary('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alumnos');
    }
};
