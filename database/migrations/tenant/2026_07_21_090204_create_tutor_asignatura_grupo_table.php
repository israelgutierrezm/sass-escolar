<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tutor_asignatura_grupo (TENANT) — tutor ACADÉMICO.
 *
 * Acompaña al docente en lo académico; NO es un padre ni familiar (ese es el
 * Módulo 13) ni el tutor de admisión del CRM (Módulo 4). Se ancla al mismo
 * asignatura_grupo pero separado de los docentes, con alcance por columnas
 * booleanas: normalmente ve y comenta, pero no califica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_asignatura_grupo', function (Blueprint $table) {
            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->boolean('puede_ver')->default(true);
            $table->boolean('puede_calificar')->default(false);
            $table->boolean('puede_comentar')->default(true);
            $table->auditoria();

            $table->primary(['asignatura_grupo_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_asignatura_grupo');
    }
};
