<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * biblioteca_enlaces (TENANT) — los recursos que la escuela publica para sus
 * alumnos: bases de datos, revistas, repositorios, lo que sea que viva en otra
 * dirección.
 *
 * ── Por qué la imagen es una URL y no un archivo de esta tabla ─────────────
 * El sistema ya tiene por dónde suben las imágenes del contenido —disco
 * privado, uuid en la dirección, servidas detrás de sesión—. Guardar aquí una
 * ruta propia significaría un segundo sitio donde viven imágenes, con sus
 * propias reglas de formato y su propia manera de servirlas, para exactamente
 * el mismo problema. Se guarda la dirección que devuelve el subidor de siempre.
 *
 * ── Por qué `orden` y no ordenar por nombre ────────────────────────────────
 * El orden lo decide quien publica: lo que la escuela quiere que se vea primero
 * no es lo que empieza con A. Es entero suelto y no consecutivo sin huecos:
 * reacomodar intercambia dos valores, y exigir 1..N obligaría a reescribir la
 * tabla entera cada vez que alguien sube un renglón.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biblioteca_enlaces', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 150);
            $table->string('descripcion', 300)->nullable();
            $table->string('url', 2048);

            // Nula = enlace directo, sin tarjeta con imagen. Es la otra forma de
            // publicar que pidió la escuela, no un enlace a medio configurar.
            $table->string('imagen_url', 2048)->nullable();

            $table->unsignedSmallInteger('orden')->default(0);

            // Retirar un enlace sin borrarlo: una base de datos que se cae por
            // mantenimiento vuelve la semana que viene, y volver a capturarla
            // con su imagen es trabajo tirado.
            $table->boolean('activo')->default(true);

            $table->auditoria();

            $table->index(['activo', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biblioteca_enlaces');
    }
};
