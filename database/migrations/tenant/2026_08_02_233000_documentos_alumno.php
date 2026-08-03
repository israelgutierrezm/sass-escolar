<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los documentos que la escuela le pide a un ALUMNO.
 *
 * ── Por qué una tabla propia y no `expediente_documentos` ──────────────────
 * Aquélla cuelga de `aspirante_id`: es el expediente de admisión, el que se
 * arma para decidir si entra. Un alumno dado de alta directamente —un traslado,
 * una convalidación— nunca fue aspirante y no tendría dónde guardar nada. Y
 * mezclar los dos momentos convierte «documentos de admisión» y «documentos
 * que debes tener al corriente» en la misma lista, cuando el primero se cierra
 * y el segundo vive todo el plan de estudios.
 *
 * Es la misma forma que `documentos_docente`, que ya lleva tiempo funcionando:
 * cuelga de la persona, un renglón por tipo de documento, y re-subir reemplaza.
 *
 * ── El ámbito ya existía ───────────────────────────────────────────────────
 * `DocumentoRequerido::AMBITO_ALUMNO` estaba en el catálogo desde el principio,
 * y hasta ahora no lo consumía nadie: la escuela podía marcar un documento como
 * requerido a los alumnos y ese requisito no aparecía en ninguna pantalla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_alumno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('alumnos', 'persona_id')->cascadeOnDelete();
            $table->foreignId('documento_id')->constrained('documentos_requeridos');
            $table->string('descripcion', 100)->nullable();
            $table->string('url', 500);
            $table->foreignId('estado_documento_id')->constrained('estados_documento');

            // Algunos vencen: seguro facultativo, certificado médico, constancia
            // de domicilio.
            $table->date('vigencia')->nullable();

            // Por qué se rechazó. Sin esto, «rechazado» obliga al alumno a
            // adivinar qué corregir antes de volver a subirlo.
            $table->string('observaciones', 255)->nullable();

            $table->auditoria();

            // Un renglón por tipo: volver a subir el mismo tipo reemplaza, no
            // acumula copias de la misma acta de nacimiento.
            $table->unique(['persona_id', 'documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_alumno');
    }
};
