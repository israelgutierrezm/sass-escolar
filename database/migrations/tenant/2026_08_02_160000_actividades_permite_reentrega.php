<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Si el alumno puede volver a entregar después de haber entregado.
 *
 * Hasta ahora siempre podía, y eso no siempre es lo que la escuela quiere: hay
 * trabajos que se entregan una vez y ya —un examen práctico, una autoevaluación,
 * cualquier cosa donde volver a subir sea rehacerla con la retroalimentación del
 * docente en la mano—. Y hay tareas donde reentregar es justo el punto: se
 * corrige y se vuelve a mandar.
 *
 * Nace en `true` porque es como se comportó el sistema hasta hoy: cambiar el
 * valor por omisión le habría cerrado la puerta, sin avisar, a las entregas que
 * ya estaban en curso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->boolean('permite_reentrega')->default(true)->after('permite_tarde');
        });
    }

    public function down(): void
    {
        Schema::table('actividades', function (Blueprint $table) {
            $table->dropColumn('permite_reentrega');
        });
    }
};
