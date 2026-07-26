<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo familiar: una persona (padre/madre/tutor) ligada a un alumno.
 *
 * Es lo que faltaba para que un padre de familia SEA usuario y pueda ver la
 * información de sus hijos. El vínculo es persona↔persona (no a una matrícula):
 * un hijo puede tener varias carreras y el padre las ve todas; un padre puede
 * tener varios hijos en la escuela.
 *
 * Cada vínculo dice, además del parentesco, QUÉ puede ver ese tutor de ese
 * alumno: lo académico, lo financiero y —a futuro, con el LMS— si entra o no a
 * las materias. Se guarda por vínculo y no en el rol porque un mismo padre
 * podría tener permisos distintos para cada hijo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutores_alumno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('alumno_persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('parentesco', 30)->default('tutor'); // padre, madre, tutor, otro
            $table->boolean('puede_ver_academico')->default(true);
            $table->boolean('puede_ver_finanzas')->default(true);
            // Reservado para cuando exista el LMS: si el tutor puede entrar a la
            // materia a ver contenido/responder. Hoy no se usa, nace apagado.
            $table->boolean('acceso_materia')->default(false);
            $table->auditoria();

            // Un mismo tutor no se liga dos veces al mismo alumno.
            $table->unique(['tutor_persona_id', 'alumno_persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutores_alumno');
    }
};
