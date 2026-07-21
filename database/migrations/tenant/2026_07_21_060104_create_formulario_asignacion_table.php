<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * formulario_asignacion (TENANT) — a qué aplica un formulario.
 *
 * `aplica_a_tipo` + `aplica_a_id` son una referencia polimórfica: el destino
 * puede ser un nivel (que vive en la landlord), una carrera, una oferta o un
 * rol. Por eso `aplica_a_id` NO lleva FK; se indexa el par para las consultas.
 * Ejemplo: "antecedente en todas las licenciaturas" = una fila con
 * aplica_a_tipo='nivel' y aplica_a_id=<licenciatura>.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulario_asignacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formulario_id')->constrained('formularios')->cascadeOnDelete();
            $table->string('aplica_a_tipo', 30); // nivel / carrera / oferta / rol
            $table->unsignedBigInteger('aplica_a_id'); // sin FK: destino polimórfico
            $table->boolean('obligatorio')->default(false); // override
            $table->auditoria();

            $table->index(['aplica_a_tipo', 'aplica_a_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulario_asignacion');
    }
};
