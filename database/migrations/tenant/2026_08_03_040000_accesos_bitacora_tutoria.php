<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién abrió la bitácora de tutoría de un alumno, y cuándo.
 *
 * ── Por qué hace falta ─────────────────────────────────────────────────────
 * Lo que hay ahí dentro son conversaciones sobre la vida de un menor: bajo
 * rendimiento, situaciones familiares, canalizaciones a psicología. El permiso
 * decide quién PUEDE entrar, pero no deja rastro de quién entró: si mañana el
 * contenido de una sesión circula por la escuela, sin esto no hay forma de
 * saber por dónde salió.
 *
 * Es lo mismo que ya se hace con las entradas al sistema en `bitacora_accesos`.
 * Tabla propia porque aquélla registra QUIÉN entró; ésta necesita además SOBRE
 * QUIÉN se consultó, que es justo el dato que la vuelve útil.
 *
 * ── Se registra la consulta, no el contenido ───────────────────────────────
 * Se guarda cuántas sesiones se le mostraron y cuántas quedaron reservadas, no
 * lo que decían. Una auditoría que copie lo vigilado multiplica el problema que
 * intenta resolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accesos_bitacora_tutoria', function (Blueprint $table) {
            $table->id();

            // Quién miró.
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            // De quién. Si el alumno se borra, el rastro se va con él: es su
            // dato personal, y conservarlo sin él no protege a nadie.
            $table->foreignId('alumno_persona_id')->constrained('personas')->cascadeOnDelete();

            $table->unsignedSmallInteger('sesiones_vistas')->default(0);

            /*
             * Cuántas quedaron reservadas. Distingue a quien leyó un expediente
             * completo de quien topó con una pared: al revisar una filtración,
             * no es lo mismo.
             */
            $table->unsignedSmallInteger('confidenciales_ocultas')->default(0);

            $table->string('ip', 45)->nullable();

            /*
             * `creado_en` y no timestamps: un registro de auditoría no se
             * actualiza nunca. Tener `updated_at` invitaría a pensar que sí.
             */
            $table->timestamp('creado_en')->useCurrent();

            $table->index(['alumno_persona_id', 'creado_en']);
            $table->index(['persona_id', 'creado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_bitacora_tutoria');
    }
};
