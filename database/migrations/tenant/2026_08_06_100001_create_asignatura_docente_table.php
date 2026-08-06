<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * asignatura_docente (TENANT) — qué materias PUEDE impartir cada docente.
 *
 * Distinta de `docente_asignatura_grupo`, que dice qué está impartiendo AHORA
 * en un grupo concreto. Ésta es su perfil: de qué sabe. Sin ella no hay forma
 * de proponerle una materia a nadie —el generador tendría que ofrecer cualquier
 * docente para cualquier cosa— y tampoco de contestar «¿a quién le puedo dar
 * Cálculo si falta el titular?», que es la pregunta de todos los semestres.
 *
 * ── Preferencia, no sólo capacidad ─────────────────────────────────────────
 * Un docente puede dar seis materias y querer dar dos. Si sólo se guardara
 * «puede», el generador repartiría al azar entre ellas y produciría horarios
 * técnicamente válidos que nadie quiere. La preferencia no restringe: ordena.
 *
 * ── Por asignatura y no por materia del plan ───────────────────────────────
 * Saber Cálculo es saber Cálculo, aunque aparezca en cuatro planes con cuatro
 * claves distintas. Ligarlo a `plan_materias` obligaría a repetir el perfil de
 * cada docente por cada plan donde exista la materia, y a corregirlo en todos
 * cuando alguien deje de darla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignatura_docente', function (Blueprint $table) {
            $table->foreignId('asignatura_id')->constrained('asignaturas')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('docentes', 'persona_id')->cascadeOnDelete();

            /*
             * 0 = puede darla · 1 = prefiere darla · -1 = sólo si no hay de otra.
             *
             * Tres niveles y no una estrella: «prefiere» ordena hacia arriba y
             * «sólo si no hay de otra» hacia abajo, que es exactamente lo que
             * una coordinación dice de viva voz al repartir carga.
             */
            $table->smallInteger('preferencia')->default(0);

            $table->auditoria();

            $table->primary(['asignatura_id', 'persona_id']);
            $table->index('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignatura_docente');
    }
};
