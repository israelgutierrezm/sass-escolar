<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las imágenes que el docente mete DENTRO del material de una lección.
 *
 * ── Por qué una tabla y no sólo un archivo ─────────────────────────────────
 * El contenido es HTML y la imagen viaja ahí como `<img src="…">`. Esa
 * dirección tiene que cumplir tres cosas a la vez: ser estable (el HTML queda
 * guardado y se lee durante todo el semestre), no exponer la ruta del disco, y
 * poder cerrarse a quien no tiene sesión. Un archivo suelto en `public/` falla
 * en las dos últimas.
 *
 * Por eso el archivo vive en el disco privado y la dirección pública es
 * `/lms/imagenes/{uuid}`: un identificador que no se puede adivinar contando
 * —con id autoincremental, quien pidiera el 1, el 2, el 3… se llevaría el
 * material entero de la escuela—.
 *
 * ── Sin dueño ──────────────────────────────────────────────────────────────
 * No cuelga de la actividad a propósito. Se sube MIENTRAS se escribe, antes de
 * que la actividad exista, y el mismo archivo puede acabar pegado en dos
 * lecciones. Lo que se guarda es quién la subió, que es lo que hace falta para
 * responder por ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imagenes_contenido', function (Blueprint $table) {
            $table->id();

            // Lo que va en la URL. No es la PK: la PK se cuenta, esto no.
            $table->uuid('uuid')->unique();

            $table->string('ruta');
            $table->string('nombre_original');
            $table->string('mime', 100);
            $table->unsignedInteger('tamano');

            $table->foreignId('subida_por')->nullable()->constrained('usuarios')->nullOnDelete();

            // `auditoria()` y no `timestamps()`: el modelo usa `TieneAuditoria`,
            // que escribe también `created_by` y `updated_by`.
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imagenes_contenido');
    }
};
