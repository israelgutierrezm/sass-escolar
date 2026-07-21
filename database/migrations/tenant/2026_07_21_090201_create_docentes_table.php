<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docentes (TENANT) — rol materializado. PK = persona_id; no duplica datos de
 * persona. `edicion_contenido` gobierna qué puede editar en el LMS:
 * 0 ninguno / 1 solo su grupo / 2 todos (del legacy IMEP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docentes', function (Blueprint $table) {
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('clave_profesor', 50)->nullable();
            $table->string('cedula_profesional', 30)->nullable();
            $table->foreignId('tipo_docente_id')->nullable()->constrained('tipos_docente');
            $table->foreignId('situacion_id')->constrained('situaciones_docente');
            $table->smallInteger('edicion_contenido')->default(1);
            $table->auditoria();

            $table->primary('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docentes');
    }
};
