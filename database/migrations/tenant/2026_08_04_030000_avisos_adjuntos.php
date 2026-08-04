<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que acompaña a un aviso: un PDF, un enlace.
 *
 * ── Por qué no basta con el texto ──────────────────────────────────────────
 * «El reglamento cambió» sin el reglamento obliga a la persona a ir a
 * buscarlo, y la mitad no lo hace. Pegar la dirección dentro del texto tampoco
 * sirve para un archivo: la escuela tendría que subirlo a otro sitio, y ahí es
 * donde los enlaces se caen a mitad de semestre.
 *
 * ── Archivos y enlaces en la misma tabla ───────────────────────────────────
 * Son la misma cosa para quien recibe el aviso —algo más que consultar, con su
 * nombre— y se listan juntos en el mismo sitio. Lo que cambia es de dónde sale
 * la dirección: `ruta` para lo que vive en el disco de la escuela, `url` para
 * lo de fuera. Separarlos en dos tablas obligaría a unir dos consultas para
 * pintar una lista.
 *
 * ── Y por qué el uuid ──────────────────────────────────────────────────────
 * El archivo se sirve por una dirección que queda en manos de quien recibió el
 * aviso. Con un id que se cuenta, quien pidiera el 1, el 2, el 3… se llevaría
 * los adjuntos de todos los avisos de la escuela, incluidos los que no le
 * tocaban.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_adjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aviso_id')->constrained('avisos')->cascadeOnDelete();

            // archivo | enlace
            $table->string('tipo', 20);

            // Cómo se llama en la lista. Para un archivo nace de su nombre
            // original; para un enlace lo escribe quien publica —«Reglamento
            // 2026» dice más que la dirección cruda—.
            $table->string('titulo', 200);

            /*
             * Sólo uno de los dos según el tipo.
             *
             * No se fuerza con un CHECK a propósito: la regla vive en la
             * validación, y una restricción de base que la duplique es una
             * segunda verdad que hay que migrar cada vez que se admita un tipo
             * nuevo —un video incrustado, por ejemplo—.
             */
            $table->string('ruta', 255)->nullable();
            $table->string('url', 2048)->nullable();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('nombre_original', 255)->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamano')->nullable();

            $table->unsignedSmallInteger('orden')->default(0);

            $table->auditoria();

            $table->index(['aviso_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_adjuntos');
    }
};
