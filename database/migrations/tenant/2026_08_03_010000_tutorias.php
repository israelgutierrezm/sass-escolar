<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué alumnos acompaña un tutor educativo.
 *
 * ── Por qué hacía falta ────────────────────────────────────────────────────
 * El rol existía y no tenía a quién tutorar. Sus permisos —`ver-alumnos` y
 * `ver-kardex`— le abrían el listado de TODA la escuela y el kárdex de
 * cualquiera, no por descuido de quien los asignó sino porque no había nada por
 * lo que acotarlo: sin este vínculo, «sus» alumnos no eran un conjunto que el
 * sistema pudiera nombrar.
 *
 * ── No confundir con `tutores_alumno` ──────────────────────────────────────
 * Aquélla tiene `parentesco` y es el vínculo FAMILIAR: el papá, la mamá, la
 * abuela que recoge. Ésta es la tutoría ACADÉMICA —el docente u orientador que
 * da seguimiento a un grupo de alumnos—, y son cosas distintas: un padre no
 * revisa avance curricular y un tutor educativo no recoge a nadie a la salida.
 *
 * ── Por ciclo ──────────────────────────────────────────────────────────────
 * La tutoría se reasigna cada ciclo, y el histórico importa: al revisar por qué
 * un alumno se rezagó en 2025 hay que poder saber quién lo acompañaba entonces.
 * Por eso el ciclo forma parte de la llave y no se sobrescribe la fila anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutorias', function (Blueprint $table) {
            $table->id();

            // El tutor es una PERSONA, no un docente: hay escuelas donde tutora
            // el orientador o el coordinador, que no imparten materia.
            $table->foreignId('tutor_persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('alumno_persona_id')->constrained('alumnos', 'persona_id')->cascadeOnDelete();
            $table->foreignId('ciclo_id')->nullable()->constrained('ciclos')->nullOnDelete();

            /*
             * Se desactiva en vez de borrarse cuando la tutoría termina antes
             * de tiempo: el registro de que existió es parte del expediente.
             */
            $table->boolean('activa')->default(true);

            $table->auditoria();

            // Un alumno tiene UN tutor por ciclo. Dos personas acompañando al
            // mismo alumno en el mismo periodo es un error de captura, no un
            // caso de uso.
            $table->unique(['alumno_persona_id', 'ciclo_id'], 'tutorias_alumno_ciclo_unica');

            $table->index(['tutor_persona_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorias');
    }
};
