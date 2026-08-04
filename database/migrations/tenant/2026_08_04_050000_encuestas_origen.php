<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué plantilla salió cada copia.
 *
 * ── Por qué hace falta ─────────────────────────────────────────────────────
 * Aplicar una encuesta copia sus preguntas, así que cada aplicación acaba con
 * un cuestionario propio y sin parentesco visible. Sin esta columna, comparar
 * el ciclo 2025-2 con el 2026-1 obligaba a adivinar que son «el mismo
 * instrumento» por el título, y basta que alguien renombre una aplicación para
 * que la comparativa deje de encontrarlas.
 *
 * Se apunta SIEMPRE a la plantilla raíz —no a la copia anterior—: si cada copia
 * apuntara a la que la precede, reunir la familia exigiría recorrer la cadena
 * hacia arriba y una plantilla borrada la partiría en dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encuestas', function (Blueprint $table) {
            // `nullOnDelete`: borrar la plantilla no puede llevarse por delante
            // los resultados de lo que se aplicó con ella. Se pierde el
            // parentesco, que es un mal mucho menor.
            $table->foreignId('origen_id')->nullable()->after('id')
                ->constrained('encuestas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('encuestas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origen_id');
        });
    }
};
