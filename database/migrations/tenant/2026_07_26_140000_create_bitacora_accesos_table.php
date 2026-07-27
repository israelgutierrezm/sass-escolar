<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de accesos y movimientos de seguridad.
 *
 * Guarda cada ENTRADA y SALIDA de una cuenta —con el equipo, el navegador y la
 * IP desde donde ocurrió— para poder mostrar a la escuela (y a los padres) un
 * registro y una gráfica de los accesos de sus alumnos. La misma tabla lleva
 * otros movimientos de seguridad (recuperación de contraseña, envío de
 * credenciales) por su `tipo`, así el rastro de una cuenta vive en un solo sitio.
 *
 * NO se borra: es un registro de auditoría. Sin FK a `usuarios` con cascade
 * fuerte porque el movimiento debe sobrevivir aunque la cuenta cambie; se liga
 * por persona, que es lo permanente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora_accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->unsignedBigInteger('usuario_id')->nullable();
            // entrada, salida, recuperacion_solicitada, recuperacion_completada,
            // credenciales_enviadas
            $table->string('tipo', 40)->index();
            $table->string('ip', 45)->nullable();
            $table->string('navegador', 60)->nullable();
            $table->string('equipo', 60)->nullable(); // sistema operativo / dispositivo
            $table->text('agente')->nullable();        // user-agent crudo
            $table->json('detalle')->nullable();
            $table->timestamp('creado_en')->useCurrent()->index();

            $table->index(['persona_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacora_accesos');
    }
};
