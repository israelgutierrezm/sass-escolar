<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto mide la imagen, para que la lección no dé un salto al cargarla.
 *
 * Sin las medidas, el navegador no sabe cuánto espacio reservar: pinta el texto,
 * llega la figura y todo lo de abajo se desplaza de golpe. El alumno estaba
 * leyendo un párrafo y se le va de la vista, o peor, iba a tocar un botón y le
 * cambia de sitio.
 *
 * Se miden al SUBIR, una vez, y viajan como `width`/`height` en el `<img>`. El
 * navegador reserva el hueco con esa proporción y la página ya no se mueve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imagenes_contenido', function (Blueprint $table) {
            $table->unsignedSmallInteger('ancho')->nullable()->after('tamano');
            $table->unsignedSmallInteger('alto')->nullable()->after('ancho');
        });
    }

    public function down(): void
    {
        Schema::table('imagenes_contenido', function (Blueprint $table) {
            $table->dropColumn(['ancho', 'alto']);
        });
    }
};
