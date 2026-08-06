<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reglas_horario (TENANT-CONFIG) — con qué criterios se arma un horario.
 *
 * Todo lo que hoy vive en la cabeza de quien arma el horario: a qué hora abre
 * la escuela, cuánto dura una clase, cuántas horas seguidas se le pueden cargar
 * a un docente. Escrito, deja de depender de que esa persona esté.
 *
 * ── Una regla base y overrides ─────────────────────────────────────────────
 * Con `ciclo_id` y `campus_id` en NULL es la regla de la escuela. Con campus,
 * la de ese campus; con ciclo, la de ese periodo. Se resuelve de lo más
 * específico a lo más general, como los planes de cobro: la mayoría de las
 * escuelas define una y nunca vuelve, y la que necesita que el campus sabatino
 * abra a las 8 no tiene que duplicar el resto.
 *
 * ── Lo que NO se guarda aquí ───────────────────────────────────────────────
 * Las horas que necesita cada materia: ésas ya están en `asignaturas`
 * (teoría/práctica) y duplicarlas invitaría a que se contradigan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglas_horario', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);

            // Alcance. Los dos en NULL = la regla base de la escuela.
            $table->foreignId('ciclo_id')->nullable()->constrained('ciclos')->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campus')->cascadeOnDelete();

            // ── La jornada ─────────────────────────────────────────────────
            $table->json('dias');              // [1,2,3,4,5]: qué días hay clase
            $table->time('hora_apertura');
            $table->time('hora_cierre');

            /*
             * El bloque es la unidad con la que se corta el día. Todo lo demás
             * —duración de una clase, descansos— se cuenta en bloques, así que
             * un horario nunca puede empezar «a mitad» de nada.
             */
            $table->smallInteger('minutos_bloque')->default(60);

            // ── Cómo se parte una materia en la semana ─────────────────────
            /*
             * Una materia de 5 horas se puede dar 2+2+1 o 3+2, pero no 5
             * seguidas ni cinco días de una hora. Estos dos números son
             * exactamente esa conversación.
             */
            $table->smallInteger('bloques_min_por_sesion')->default(1);
            $table->smallInteger('bloques_max_por_sesion')->default(3);
            $table->smallInteger('max_sesiones_por_dia')->default(1); // de la MISMA materia

            // ── Límites de carga docente ───────────────────────────────────
            $table->smallInteger('horas_max_dia_docente')->nullable();
            $table->smallInteger('horas_max_semana_docente')->nullable();
            $table->smallInteger('minutos_descanso_docente')->default(0); // entre clase y clase

            /*
             * Cómo se reparte la carga entre quienes pueden dar una materia.
             *
             * `concentrar`: a un docente se le dan TODAS las materias que puede
             * impartir antes de pasar al siguiente —menos gente, horarios más
             * compactos—. `repartir`: se distribuye entre todos los aptos.
             * Es la regla que pediste de «todas las materias o sólo unas».
             */
            $table->string('reparto', 20)->default('repartir');

            /*
             * ¿Se puede dejar un hueco en el horario de un grupo?
             *
             * Un grupo con clase a las 7 y a las 11 tiene a treinta alumnos sin
             * nada que hacer en medio. A veces no hay remedio; que sea una
             * decisión y no un accidente.
             */
            $table->boolean('permite_huecos_grupo')->default(false);

            $table->boolean('activa')->default(true);
            $table->auditoria();

            /*
             * Una sola regla por alcance. Dos reglas base contradiciéndose es
             * el tipo de cosa que nadie nota hasta que el horario sale raro.
             */
            $table->unique(['ciclo_id', 'campus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_horario');
    }
};
