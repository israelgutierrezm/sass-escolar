<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contadores_acta (TENANT) — consecutivo del folio de acta, por ámbito.
 *
 * Gemela de `contadores_matricula` y por el mismo motivo: dos docentes
 * cerrando actas al mismo tiempo NO deben obtener el mismo folio. El folio se
 * imprime y se firma; un duplicado es un problema de archivo, no un detalle.
 *
 * SIN `id` AUTO_INCREMENT, igual que `contadores_matricula`. La lección está
 * documentada en docs/decisiones.md: un INSERT sobre una tabla con
 * AUTO_INCREMENT sobreescribe LAST_INSERT_ID() y rompe el
 * `INSERT ... ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1)` del
 * que depende la atomicidad. La PK es la propia clave de ámbito.
 *
 * Tampoco lleva soft delete: ocultar un contador reiniciaría la numeración y
 * emitiría folios repetidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contadores_acta', function (Blueprint $table) {
            $table->string('clave', 150)->primary(); // p.ej. "acta|anio:2026"
            $table->unsignedBigInteger('valor')->default(0); // último consecutivo entregado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contadores_acta');
    }
};
