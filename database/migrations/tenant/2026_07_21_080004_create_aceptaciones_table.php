<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * aceptaciones (TENANT) — quién aceptó qué documento, en qué versión, cuándo y
 * desde qué IP. Es la fuente de verdad legal (LFPDPPP y reglamento interno);
 * el booleano `aspirantes.acepto_terminos` queda solo como atajo de UI.
 *
 * Cuelga de `personas` —no de aspirantes ni de alumnos— porque la misma
 * persona acepta documentos en distintas etapas de su vida en la escuela y la
 * aceptación no debe perderse al convertirse en alumno.
 *
 * `version` se copia (no se deriva por FK) para congelar qué texto se aceptó
 * aunque el catálogo cambie después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aceptaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('documento_normativo_id')->constrained('documentos_normativos');
            $table->integer('version'); // congelada al momento de aceptar
            $table->dateTime('aceptado_en');
            $table->string('ip', 45)->nullable();
            $table->auditoria();

            $table->unique(['persona_id', 'documento_normativo_id']);
            $table->index(['documento_normativo_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceptaciones');
    }
};
