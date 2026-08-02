<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contenido dentro de la actividad, y constancia de haberlo visto.
 *
 * ── Contenido no es lo mismo que instrucciones ─────────────────────────────
 * `instrucciones` dice QUÉ HACER —«sube tu ensayo en PDF»—; `contenido` es el
 * MATERIAL con el que se aprende: el texto de la lección, un video embebido, un
 * SCORM, una infografía. Meterlos en el mismo campo obligaría a que cada
 * actividad eligiera entre explicarse o enseñar, cuando lo normal es lo que se
 * ve en cualquier curso en línea: primero se lee, luego se hace.
 *
 * Es HTML porque el editor que ya usa el sistema (TipTap) emite HTML, y porque
 * es lo que permite pegar un `iframe` de lo que la escuela ya tenga producido
 * sin pedirle que lo rehaga.
 *
 * ── Por qué una tabla para lo visto ────────────────────────────────────────
 * Una lectura no se entrega, así que no cabe en `entregas`. Pero sin registro de
 * que se leyó, un curso hecho de lecturas no tendría avance que mostrar: el
 * alumno vería 0% para siempre por más que recorriera todo. Aquí se guarda el
 * hecho simple de que esta persona ya pasó por esta actividad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->longText('contenido')->nullable()->after('instrucciones');
        });

        Schema::create('actividad_vistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actividad_id')->constrained('actividades')->cascadeOnDelete();
            $table->foreignId('inscripcion_id')->constrained('inscripcion')->cascadeOnDelete();

            $table->timestamp('vista_en');

            $table->timestamps();
            $table->softDeletes();

            // Una vez por alumno y actividad: se registra que pasó, no cuántas
            // veces. Contar visitas mediría otra cosa y nadie la pidió.
            $table->unique(['actividad_id', 'inscripcion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividad_vistas');

        Schema::table('actividades', function (Blueprint $table) {
            $table->dropColumn('contenido');
        });
    }
};
