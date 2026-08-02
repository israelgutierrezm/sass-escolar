<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguir «la abrí» de «ya la terminé».
 *
 * `vista_en` sirve para volver donde uno se quedó; no sirve para decir que se
 * completó. Marcar una lección de veinte minutos como hecha por el solo hecho
 * de abrirla llenaría la barra de progreso de mentiras: el alumno vería 100 %
 * de un curso que apenas hojeó, y esa barra es justamente lo que tiene que
 * poder creer.
 *
 * Por eso el avance de una lectura lo declara el alumno con un botón, como en
 * cualquier plataforma seria, y aquí queda cuándo lo hizo. Lo que se entrega
 * —actividad, foro, examen— no necesita este campo: su constancia es la
 * entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actividad_vistas', function (Blueprint $table) {
            $table->timestamp('completada_en')->nullable()->after('vista_en');
        });
    }

    public function down(): void
    {
        Schema::table('actividad_vistas', function (Blueprint $table) {
            $table->dropColumn('completada_en');
        });
    }
};
