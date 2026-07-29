<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se elimina `documento_carrera`: era un vestigio. Los documentos que se piden a
 * un aspirante NO salían de aquí sino de `documento_ambitos` (por ámbito/etapa),
 * que es el flujo real de admisiones. Los checkboxes «Documentos de admisión»
 * del formulario de carrera guardaban datos que nadie consumía.
 *
 * La documentación requerida por PLAN de estudios y su administración se harán
 * desde Admisiones sobre ese flujo (pendiente). Académico queda para la carga de
 * datos generales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('documento_carrera');
    }

    public function down(): void
    {
        Schema::create('documento_carrera', function (Blueprint $table) {
            $table->foreignId('documento_id')->constrained('documentos_requeridos')->cascadeOnDelete();
            $table->foreignId('carrera_id')->constrained('carreras')->cascadeOnDelete();
            $table->auditoria();

            $table->primary(['documento_id', 'carrera_id']);
        });
    }
};
