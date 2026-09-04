<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El rastro de las alertas del módulo formativo.
 *
 * ── Una TABLA de rastro y no una columna en el aviso ──────────────────────
 * Lo que hace falta contestar es «¿ya se avisó de ESTO?», y «esto» es la pareja
 * (expediente, evento) más una referencia —el informe concreto, el convenio
 * concreto—. Con una columna en `avisos` habría que recorrerlos todos y
 * confiar en el texto; aquí es un índice único.
 *
 * ── Y el rastro sobrevive al aviso ────────────────────────────────────────
 * `aviso_id` es NULLABLE con `nullOnDelete`: un aviso se puede borrar, y si el
 * rastro se fuera con él el comando volvería a avisar a la mañana siguiente.
 * Es exactamente la lección de `recordatorios_cobranza`.
 *
 * ── El ÚNICO es lo que impide el goteo diario ─────────────────────────────
 * Un recordatorio que llega treinta mañanas seguidas deja de leerse al tercero.
 * Y no basta un `SELECT` previo: dos corridas simultáneas lo pasan las dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alertas_proceso')) {
            return;
        }

        Schema::create('alertas_proceso', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes_proceso')->cascadeOnDelete();

            // Qué se avisó: 'informe_por_vencer', 'informe_vencido',
            // 'plazo_por_vencer', 'plazo_vencido', 'horas_lejos'…
            // Es una clave de CÓDIGO —cada una es una rama con su texto y su
            // destinatario—, así que no es catálogo: una fila nueva no haría
            // nada. Mismo argumento que `tipos_actividad`.
            $tabla->string('evento', 40);

            /*
             * A QUÉ se refiere dentro del expediente: el id del informe, o una
             * marca del hito. Sin ella, «ya se avisó de un informe» impediría
             * avisar del segundo — y con ella el único distingue cada uno.
             */
            $tabla->string('referencia', 60)->default('');

            $tabla->foreignId('aviso_id')->nullable()->constrained('avisos')->nullOnDelete();
            $tabla->timestamp('emitida_en');
            $tabla->auditoria();

            $tabla->unique(['expediente_id', 'evento', 'referencia'], 'alerta_formativa_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_proceso');
    }
};
