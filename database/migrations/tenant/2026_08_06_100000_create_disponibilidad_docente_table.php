<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * disponibilidad_docente (TENANT) — cuándo puede dar clase cada docente.
 *
 * El insumo sin el cual no se puede generar un horario: hoy la escuela lo
 * sabe de memoria o en un WhatsApp, y al armar el horario alguien lo reconstruye
 * de cabeza. Aquí queda escrito, y el docente puede declararlo él mismo.
 *
 * ── Plantilla y ajustes ────────────────────────────────────────────────────
 * `ciclo_id` en NULL es la disponibilidad HABITUAL: la que el docente declara
 * una vez y vale para todos los ciclos. Con un ciclo puesto, son sus horarios
 * para ESE periodo.
 *
 * Y cuando hay ajustes de un ciclo, REEMPLAZAN por completo a la plantilla, no
 * se suman. Sumar parecía más flexible hasta preguntarse cómo se QUITA una
 * franja: haría falta una fila que dijera «este martes no», y a partir de ahí
 * nadie podría leer la disponibilidad de un docente sin ejecutar el algoritmo
 * mentalmente. Redefinir el ciclo completo se lee de un vistazo.
 *
 * ── Modalidad ──────────────────────────────────────────────────────────────
 * Un docente puede estar disponible de 7 a 9 sólo en línea y de 10 a 14 en el
 * campus. Importa para generar: una clase en línea no ocupa aula ni obliga a
 * que quepa el traslado, y una presencial sí.
 *
 * No lleva `campus_id`: dónde puede dar clase ya lo dice `campus_docente`, y
 * repetirlo aquí abriría la puerta a que las dos respuestas se contradigan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidad_docente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('docentes', 'persona_id')->cascadeOnDelete();

            // NULL = su disponibilidad habitual. Con ciclo = la de ese periodo.
            $table->foreignId('ciclo_id')->nullable()->constrained('ciclos')->cascadeOnDelete();

            $table->smallInteger('dia_semana'); // 1 = lunes … 7 = domingo, como en horarios_asignatura_grupo
            $table->time('hora_inicio');
            $table->time('hora_fin');

            // presencial | en_linea | ambas
            $table->string('modalidad', 20)->default('ambas');

            // Por qué no puede: «tengo otro trabajo hasta las 2». Ayuda a quien
            // negocia un cambio, y a nadie le sirve un hueco sin explicación.
            $table->string('nota', 200)->nullable();

            $table->auditoria();

            $table->index(['persona_id', 'ciclo_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidad_docente');
    }
};
