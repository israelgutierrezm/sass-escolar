<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disciplina: incidencias y sanciones de los alumnos.
 *
 * Función pedida por el cliente; NO está en la spec, así que se diseña siguiendo
 * los patrones del proyecto en vez de inventar un dominio nuevo.
 *
 * ── Alumno = MATRÍCULA, no persona ─────────────────────────────────────────
 * Todo cuelga de `matricula_oferta`: una persona con dos carreras lleva la
 * conducta de cada una por separado, igual que su historial. Corregir su
 * identidad alcanza a las dos; una incidencia es de UNA matrícula.
 *
 * ── Los tipos son CATÁLOGO configurable (regla 4) ──────────────────────────
 * `tipos_incidencia` con `nivel` de gravedad que fija la escuela —no un enum
 * leve/media/grave cableado— y `tipos_sancion` con la bandera `tiene_vigencia`:
 * «suspensión» la enciende y pide fechas, «amonestación» no. Así «tres días de
 * suspensión» y «llamada de atención» son dos FILAS que se comportan distinto,
 * no dos ramas de código.
 *
 * ── La sanción CITA la incidencia, con un pivote ───────────────────────────
 * Una sanción puede salir de una incidencia, de VARIAS, o de ninguna (hay
 * sanciones directas). Un pivote lo cubre sin obligar: la FK nullable en
 * `sanciones` diría «una sola incidencia», que es menos de lo que pasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->catalogos();
        $this->incidencias();
        $this->sanciones();
        $this->pivote();
    }

    public function down(): void
    {
        // De la más dependiente a la menos: el pivote antes que sus dos tablas.
        Schema::dropIfExists('incidencia_sancion');
        Schema::dropIfExists('sanciones');
        Schema::dropIfExists('incidencias');
        Schema::dropIfExists('tipos_sancion');
        Schema::dropIfExists('tipos_incidencia');
    }

    private function catalogos(): void
    {
        if (! Schema::hasTable('tipos_incidencia')) {
            Schema::create('tipos_incidencia', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);
                $t->string('descripcion', 255)->nullable();

                /*
                 * Gravedad como NÚMERO que la escuela ordena, no un enum.
                 * 1 = leve, sube según la escuela. Sirve para ordenar el
                 * historial por lo más serio y para colorear, sin cablear una
                 * lista fija de niveles que una escuela querrá ampliar.
                 */
                $t->unsignedTinyInteger('nivel')->default(1);

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        if (! Schema::hasTable('tipos_sancion')) {
            Schema::create('tipos_sancion', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);
                $t->string('descripcion', 255)->nullable();

                /*
                 * La bandera que gobierna el formulario: una sanción con
                 * vigencia —suspensión— captura desde/hasta; una puntual
                 * —amonestación— no. Es lo que hace del catálogo algo
                 * configurable y no cuatro casos especiales en el código.
                 */
                $t->boolean('tiene_vigencia')->default(false);

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }
    }

    private function incidencias(): void
    {
        if (Schema::hasTable('incidencias')) {
            return;
        }

        Schema::create('incidencias', function (Blueprint $t) {
            $t->id();

            $t->foreignId('matricula_oferta_id')->constrained('matricula_oferta');
            $t->foreignId('tipo_incidencia_id')->constrained('tipos_incidencia');

            // Cuándo OCURRIÓ, no cuándo se capturó: una incidencia del lunes se
            // registra el miércoles, y ordenarla por `created_at` mentiría.
            $t->date('fecha');

            $t->text('descripcion');

            /*
             * Quién la levantó. `personas.id` y no un rol: la reporta control
             * escolar o el docente, y a la hora de revisar importa el nombre de
             * quien la vio, no el puesto. Sin FK cruzada a nada raro: es una
             * persona del tenant.
             */
            $t->foreignId('reportada_por')->nullable()->constrained('personas');

            $t->auditoria();

            // El historial de conducta de un alumno se lee por matrícula y
            // fecha; es la consulta que sostiene tanto el expediente como el
            // portal del padre.
            $t->index(['matricula_oferta_id', 'fecha']);
        });
    }

    private function sanciones(): void
    {
        if (Schema::hasTable('sanciones')) {
            return;
        }

        Schema::create('sanciones', function (Blueprint $t) {
            $t->id();

            $t->foreignId('matricula_oferta_id')->constrained('matricula_oferta');
            $t->foreignId('tipo_sancion_id')->constrained('tipos_sancion');

            // Cuándo se aplica.
            $t->date('fecha');

            /*
             * La vigencia, sólo para las que la tienen. NULL cuando el tipo no
             * la usa, y NULL y no la fecha porque «suspendido del 5 al 8» es un
             * dato distinto de «amonestado el 5».
             */
            $t->date('desde')->nullable();
            $t->date('hasta')->nullable();

            $t->text('motivo');

            $t->foreignId('aplicada_por')->nullable()->constrained('personas');

            $t->auditoria();

            $t->index(['matricula_oferta_id', 'fecha']);
        });
    }

    private function pivote(): void
    {
        if (Schema::hasTable('incidencia_sancion')) {
            return;
        }

        Schema::create('incidencia_sancion', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sancion_id')->constrained('sanciones')->cascadeOnDelete();
            $t->foreignId('incidencia_id')->constrained('incidencias')->cascadeOnDelete();

            // Una incidencia no se cita dos veces en la misma sanción.
            $t->unique(['sancion_id', 'incidencia_id']);
        });
    }
};
