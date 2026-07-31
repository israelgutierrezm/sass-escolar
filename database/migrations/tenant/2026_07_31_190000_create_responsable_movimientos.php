<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * responsable_movimientos (TENANT) — bitácora de movimientos de un responsable
 * de firma (alta, reactivación, desactivación, renovación de certificado, carga
 * de llave, cambios de cargo/título).
 *
 * Como una persona (por CURP) es UN solo registro que se reactiva en lugar de
 * duplicarse, aquí queda la traza de su historia: cuándo entró, cuándo salió y
 * cuándo volvió. Es un log append-only: no lleva soft delete ni updated_at; si
 * se elimina la persona del historial, su bitácora se va con ella (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsable_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('responsable_id')->constrained('responsables')->cascadeOnDelete();

            // alta | reactivacion | desactivacion | renovacion_certificado | carga_llave | actualizacion
            $table->string('accion', 40);
            $table->string('detalle', 500)->nullable();

            // Autor del movimiento: id del usuario + snapshot de su nombre para que
            // la bitácora se lea aunque el usuario cambie o se elimine.
            $table->foreignId('realizado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('realizado_por_nombre', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['responsable_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsable_movimientos');
    }
};
