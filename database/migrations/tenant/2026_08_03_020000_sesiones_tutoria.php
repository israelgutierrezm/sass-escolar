<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bitácora de un tutor: qué se habló con cada tutorado y en qué quedaron.
 *
 * ── Para qué sirve de verdad ───────────────────────────────────────────────
 * Una tutoría sin registro es una plática que se olvida. Lo que la vuelve útil
 * es poder abrir la ficha tres meses después y ver que en septiembre se acordó
 * que entregaría el trabajo pendiente, y que en octubre seguía sin entregarlo.
 * También protege al tutor: cuando alguien pregunta por qué no se detectó a
 * tiempo un rezago, la bitácora responde.
 *
 * ── Cuelga de la TUTORÍA, no del alumno ────────────────────────────────────
 * Así cada sesión queda amarrada a quién la dio y en qué ciclo. Si el alumno
 * cambia de tutor, lo anotado por el anterior sigue siendo del anterior: no se
 * le atribuye a quien llega, ni desaparece.
 *
 * ── Los acuerdos van aparte del tema ───────────────────────────────────────
 * Un solo campo de texto libre acaba siendo un párrafo donde el compromiso —lo
 * único que hay que revisar la próxima vez— queda enterrado a media frase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_tutoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutoria_id')->constrained('tutorias')->cascadeOnDelete();

            $table->date('fecha');

            // Presencial, en línea, telefónica… Sin catálogo: son tres o cuatro
            // y crear una tabla para eso obliga a administrarla.
            $table->string('modalidad', 20)->default('presencial');

            /*
             * Por qué se vieron. Se guarda la clave y no el texto para poder
             * contar: «este semestre, la mitad de mis sesiones fueron por bajo
             * rendimiento» es la clase de dato que justifica una tutoría.
             */
            $table->string('motivo', 30)->default('seguimiento');

            // Qué se habló.
            $table->text('tema');

            // En qué quedaron. Es lo que se revisa la próxima vez.
            $table->text('acuerdos')->nullable();

            /*
             * Si el alumno no llegó, la sesión igual se registra: que no acudió
             * a tres citas seguidas es información, y borrarla dejaría la
             * ausencia sin rastro.
             */
            $table->boolean('asistio')->default(true);

            $table->auditoria();

            $table->index(['tutoria_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_tutoria');
    }
};
