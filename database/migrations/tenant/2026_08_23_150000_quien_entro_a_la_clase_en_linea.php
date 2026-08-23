<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accesos_videoconferencia (TENANT) — quién entró a la clase en línea.
 *
 * La última tabla que le faltaba al Módulo 8. `videoconferencias` llevaba desde
 * el 2026-08-19 programando clases y repartiendo enlaces, pero nadie anotaba
 * quién los usaba: una clase en línea no podía pasar lista.
 *
 * ── Lo que ESTO mide, dicho antes de que alguien lo confunda ───────────────
 * **El clic en «Entrar», no la asistencia.** Se sabe que la persona pidió el
 * enlace estando la clase abierta; no se sabe si se quedó, ni si encendió la
 * cámara, ni si se durmió. Es información real y barata —quien nunca pulsó el
 * botón desde luego no entró— y por eso la pantalla dice «se conectó» y no
 * «asistió».
 *
 * Lo otro sería preguntarle al proveedor por su reporte de participantes, que
 * da minutos de permanencia. No se hace hoy: son dos APIs más (Zoom y Meet),
 * hace falta un Workspace y credenciales con las que probarlo, y a cambio del
 * 90 % del valor esto cuesta un `redirect`. Si algún día se agrega, cabe aquí:
 * `minutos` sería una columna más, no otra tabla.
 *
 * ── UNA FILA POR PERSONA Y CLASE, no una por clic ──────────────────────────
 * La pregunta que se le hace a esta tabla es «¿entró?», no «¿cuántas veces le
 * picó?». Con una fila por clic, contar asistentes exigiría un `DISTINCT` que
 * alguien olvidará algún día, y una clase con red mala —donde la gente se
 * reconecta seis veces— saldría con seis veces más «asistencia» que otra.
 *
 * Las reconexiones no se pierden: `veces` las cuenta y `ultimo_acceso` dice
 * cuándo fue la última. Eso además distingue a quien entró al principio y se
 * quedó de quien apareció al final, que es lo que un docente mira.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accesos_videoconferencia')) {
            return;
        }

        Schema::create('accesos_videoconferencia', function (Blueprint $t) {
            $t->id();

            $t->foreignId('videoconferencia_id')->constrained('videoconferencias')->cascadeOnDelete();

            /*
             * A la PERSONA y no a la inscripción.
             *
             * Por aquí entran los dos oficios —el alumno y el docente— y una
             * clase existe aunque la materia no tenga a nadie inscrito todavía.
             * Atarlo a `inscripcion` dejaría fuera al docente, que es
             * justamente de quien interesa saber si llegó a su propia clase.
             */
            $t->foreignId('persona_id')->constrained('personas');

            $t->timestamp('primer_acceso');
            $t->timestamp('ultimo_acceso');

            // Las reconexiones. Empieza en 1: la fila nace cuando ya entró una
            // vez, así que un cero no significaría nada.
            $t->unsignedSmallInteger('veces')->default(1);

            /*
             * El papel con el que entró, congelado.
             *
             * Quien da clases y además estudia puede aparecer de los dos lados
             * en materias distintas; y sobre todo, resolverlo al MIRAR el
             * listado obligaría a repreguntar la asignación de entonces, que
             * puede haber cambiado. Un acceso es un hecho fechado.
             */
            $t->string('papel', 12);

            $t->auditoria();

            // Es la llave por la que se pregunta y la que sostiene la
            // idempotencia del segundo clic.
            $t->unique(['videoconferencia_id', 'persona_id'], 'acceso_video_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_videoconferencia');
    }
};
