<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * autorizados_recoger — quién puede (o NO puede) recoger a un alumno.
 *
 * Ver `docs/plan-salida-segura.md`. Una fila es un PERMISO (`permitido=true`, un
 * tercero que la familia autoriza) o un BLOQUEO (`permitido=false`, la custodia
 * que la escuela registra). Los TUTORES no se copian aquí: se autorizan por su
 * vínculo salvo que un bloqueo lo diga; esta tabla guarda lo que el vínculo no
 * dice.
 *
 * `persona_id` es NULLABLE a propósito: a quien recoge no se le pide cuenta —una
 * abuela no la tiene—, así que la identidad es su nombre y su identificación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('autorizados_recoger')) {
            return;
        }

        Schema::create('autorizados_recoger', function (Blueprint $tabla): void {
            $tabla->id();

            $tabla->foreignId('alumno_persona_id')->constrained('personas')->cascadeOnDelete();
            // La cuenta de quien recoge, SI la tiene. Sin acción referencial que
            // la borre en cascada: si se depura una persona, el registro de a
            // quién se autorizó no debe evaporarse. `nullOnDelete` deja el nombre.
            $tabla->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();

            $tabla->string('nombre', 180);
            $tabla->string('identificacion', 120)->nullable();
            $tabla->foreignId('parentesco_id')->nullable()->constrained('parentescos')->nullOnDelete();
            $tabla->string('foto_ruta')->nullable();

            // true = AUTORIZA (tercero); false = BLOQUEA (custodia).
            $tabla->boolean('permitido')->default(true);

            $tabla->date('vigencia_desde')->nullable();
            $tabla->date('vigencia_hasta')->nullable();

            // La razón del bloqueo de custodia: queda escrita porque dentro de un
            // año alguien preguntará por qué se rechazó a esa persona.
            $tabla->string('motivo', 500)->nullable();

            $tabla->auditoria();

            // La consulta caliente es «los de ESTE alumno». La foránea de
            // alumno ya la sostiene; no se agrega otro índice «por si acaso».
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizados_recoger');
    }
};
