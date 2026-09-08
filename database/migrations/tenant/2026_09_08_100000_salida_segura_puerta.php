<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salida segura, rebanada 2: el token del QR y el registro de salida.
 *
 * Ver `docs/plan-salida-segura.md`.
 *
 * `autorizados_recoger.token` — lo que viaja en el QR de un tercero. Es un
 * CÓDIGO, no los datos: el servidor lo resuelve y valida contra el estado ACTUAL
 * (vigencia, bloqueo), así que un QR de alguien a quien ya se bloqueó se rechaza
 * aunque el papel siga circulando. Mismo criterio que la credencial. Sólo lo
 * llevan las AUTORIZACIONES —un bloqueo no tiene QR—, y lo pone el servidor, no
 * el cliente.
 *
 * `salidas_alumno` — el registro de entrega: a quién se le entregó el alumno,
 * cuándo, cómo se validó y quién lo procesó (`created_by`, el guardia).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('autorizados_recoger', 'token')) {
            Schema::table('autorizados_recoger', function (Blueprint $tabla): void {
                // Único: el token identifica UNA fila. Sirve además para
                // buscarla al escanear, así que el índice no es «por si acaso».
                $tabla->uuid('token')->nullable()->unique()->after('motivo');
            });
        }

        if (Schema::hasTable('salidas_alumno')) {
            return;
        }

        Schema::create('salidas_alumno', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('alumno_persona_id')->constrained('personas')->cascadeOnDelete();
            // Quién lo recogió, si tiene cuenta. Un tercero suele no tenerla, y
            // por eso queda su nombre. Sin cascada: el registro de una entrega
            // no se borra porque la persona se depure.
            $tabla->foreignId('recogido_por_persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $tabla->string('recogido_nombre', 180);

            // La fila de autorización que se usó (un tercero). Un tutor no tiene
            // fila, así que es nullable.
            $tabla->foreignId('autorizado_id')->nullable()->constrained('autorizados_recoger')->nullOnDelete();

            // Cómo se validó: por QR, por ser tutor, o a mano (el guardia lo
            // eligió de la lista). Se guarda para poder auditar una entrega.
            $tabla->string('como', 20);

            $tabla->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas_alumno');

        if (Schema::hasColumn('autorizados_recoger', 'token')) {
            Schema::table('autorizados_recoger', function (Blueprint $tabla): void {
                $tabla->dropColumn('token');
            });
        }
    }
};
