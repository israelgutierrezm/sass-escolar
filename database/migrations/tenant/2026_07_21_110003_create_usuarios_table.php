<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * usuarios (TENANT) — credenciales de acceso.
 *
 * NO toda persona tiene usuario (un padre puede no loguearse todavía), y una
 * persona tiene a lo más uno: de ahí el unique en persona_id.
 *
 * Cuelga de `personas`, no de `alumnos`: el login es de PERSONAS con cualquier
 * rol activo. Un aspirante necesita sesión desde el primer día para llenar
 * formularios, aceptar reglamentos, subir documentos y pagar, mucho antes de
 * ser alumno.
 *
 * `rol_activo_id` es el rol con el que la persona interactúa AHORA (conmutador
 * de rol). Gobierna permisos, menús y tema por request, y el backend valida
 * siempre que esté entre sus roles activos de `persona_rol`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('usuario', 150)->unique();
            $table->string('email', 150);
            $table->string('password');
            $table->string('url_perfil')->nullable();
            $table->boolean('conectado')->default(false);
            $table->foreignId('rol_activo_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('tema_id')->nullable()->constrained('temas')->nullOnDelete();
            $table->rememberToken();
            $table->auditoria();

            $table->unique('persona_id'); // una persona -> a lo más un usuario
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
