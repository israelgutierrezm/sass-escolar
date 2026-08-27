<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes que se mandan solos por correo.
 *
 * ── Cuelga de la VISTA, no del reporte ───────────────────────────────────
 * Programar exige haber decidido antes qué columnas y qué filtros. «Mándame la
 * cartera» no es una instrucción: «mándame la cartera vencida del campus norte,
 * con estas seis columnas» sí, y eso es exactamente lo que una vista guarda.
 *
 * ── El ROL es obligatorio, y ahí está toda la seguridad ──────────────────
 * Una corrida programada no tiene rol activo —no hay nadie con una sesión
 * abierta—, y el alcance por campus sale precisamente de ahí:
 * `Usuario::campusVisibles()` lee `persona_rol.campus_id` del rol ACTIVO. Sin
 * fijarlo habría que elegir entre no correr o mandarle por correo la escuela
 * entera a quien sólo ve un plantel.
 *
 * Y si al dueño le retiran ese rol o el permiso del reporte, la programación se
 * **suspende con su motivo escrito**. Nunca se degrada a otro alcance: correr
 * «con lo que le quede» convertiría un cambio de permisos en un correo con
 * distinto contenido que nadie pidió.
 *
 * ── `destino_id` sin foránea ─────────────────────────────────────────────
 * Mismo patrón que `evento_destinos` y `avisos_destinos`: apunta a tablas
 * distintas según el tipo, y es lo que permite agregar «por campus» mañana sin
 * migrar. A cambio, lo que apunte a algo borrado se muestra como «Ya no existe»
 * en vez de reventar.
 *
 * ── Lo que NO se construye: destinatarios por correo suelto ──────────────
 * El plan preveía un tipo `correo` para el contador externo sin cuenta, que
 * recibiría un enlace en vez del adjunto. No se construye, y por lo que se ve al
 * escribirlo: un enlace que exige sesión no lo puede abrir quien no tiene
 * cuenta, así que ese destinatario recibiría un correo con una puerta cerrada
 * —peor que no ofrecerlo, porque quien lo configure creerá que su contador
 * recibe el reporte—. Y la alternativa, mandarle el adjunto, es exfiltración por
 * diseño: un padrón con CURP saliendo a una dirección que la escuela no
 * controla, todos los lunes, sin que nadie vuelva a mirarlo.
 *
 * Quien necesite mandárselo a alguien de fuera lo descarga y lo reenvía, con su
 * nombre en el envío. Es un paso más y es el paso correcto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('programaciones_reporte')) {
            Schema::create('programaciones_reporte', function (Blueprint $tabla) {
                $tabla->id();

                $tabla->foreignId('vista_id')->constrained('vistas_reporte')->cascadeOnDelete();
                $tabla->string('nombre', 120);

                // El DUEÑO y el rol con cuyo alcance corre. Ver el docblock.
                $tabla->foreignId('persona_id')->constrained('personas');
                $tabla->foreignId('rol_id')->constrained('roles');

                $tabla->string('frecuencia', 20);
                // Día de la semana (1-7) o del mes (1-28). Null en la diaria.
                $tabla->unsignedTinyInteger('dia')->nullable();
                $tabla->time('hora');
                $tabla->string('formato', 10);

                $tabla->boolean('activa')->default(true);

                /*
                 * Suspendida NO es lo mismo que apagada.
                 *
                 * `activa` la mueve una persona; la suspensión la pone el
                 * sistema cuando deja de poder correr, y guarda por qué. Con una
                 * sola bandera no se distinguiría «la apagué» de «dejó de
                 * funcionar y nadie se enteró», que es justo lo que hay que poder
                 * enseñar en pantalla.
                 */
                $tabla->dateTime('suspendida_en')->nullable();
                $tabla->string('motivo_suspension', 255)->nullable();

                $tabla->dateTime('ultima_corrida_en')->nullable();
                $tabla->string('ultimo_estado', 20)->nullable();
                $tabla->text('ultimo_error')->nullable();

                $tabla->auditoria();

                /*
                 * Por dónde entra el comando: lo que está vivo y toca a esta
                 * hora. Empieza por `activa` porque es lo que descarta más.
                 */
                $tabla->index(['activa', 'frecuencia', 'hora']);
            });
        }

        if (! Schema::hasTable('destinatarios_reporte')) {
            Schema::create('destinatarios_reporte', function (Blueprint $tabla) {
                $tabla->id();

                $tabla->foreignId('programacion_id')
                    ->constrained('programaciones_reporte')
                    ->cascadeOnDelete();

                // `persona` o `rol`. Sin foránea: apunta a tablas distintas.
                $tabla->string('tipo', 20);
                $tabla->unsignedBigInteger('destino_id');

                $tabla->auditoria();

                $tabla->index(['programacion_id', 'tipo']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('destinatarios_reporte');
        Schema::dropIfExists('programaciones_reporte');
    }
};
