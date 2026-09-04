<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las alertas y el registro de las corridas del motor.
 *
 * ── DOS estados y no la lista de doce del pedido ───────────────────────────
 * El pedido enumera `nueva, pendiente_revision, validada, descartada, asignada,
 * contacto_pendiente, en_intervencion, en_seguimiento, escalada, resuelta,
 * cerrada, reabierta`. Los primeros son el triage de una SEÑAL; los demás
 * describen el trabajo de una persona, que es el CASO (fase 5).
 *
 * Fundirlos pondría a una señal en estado «en_intervención», y una señal no
 * interviene: es cierta o dejó de serlo. Peor: con una sola máquina, cerrar el
 * caso obligaría a mentir sobre la señal —que puede seguir siendo cierta— o a
 * dejar el caso abierto para no mentir. Separadas, cada una dice la verdad de lo
 * suyo y los doce estados existen, repartidos donde significan algo.
 *
 * Por eso hay DOS columnas de estado y ninguna sobra:
 *  - `estado_senal`: lo que dice el MOTOR. activa / resuelta / obsoleta.
 *  - `estado_triage`: lo que dice una PERSONA. nueva / validada / descartada.
 *
 * Y «resuelta» no es «obsoleta»: la primera significa que la situación mejoró
 * —con la evidencia de la mejora—, la segunda que se dejó de vigilar porque la
 * regla se apagó o el alumno salió de su alcance. Llamarlas igual haría que
 * apagar una regla se leyera como que doscientos alumnos se recuperaron.
 *
 * ── La DEDUPLICACIÓN la sostiene un índice, no un `SELECT` ─────────────────
 * Mientras la alerta siga ABIERTA, la regla no levanta otra: se actualiza la que
 * hay con el valor nuevo. Un `SELECT` previo lo pasan dos corridas simultáneas
 * —y el pedido pide explícitamente evitar «una alerta nueva diaria por la misma
 * causa»—, así que la defensa es un ÚNICO sobre una columna generada.
 *
 * Y tiene que ser generada: un único pelado sobre (matrícula, regla, materia)
 * impediría levantar una alerta nueva el año que viene, y `deleted_at` dentro
 * del único NO sirve —MySQL da dos NULL por distintos—. Es la solución de
 * `sesiones_caja`, `expedientes_proceso` y `reglas_recordatorio_cobranza`.
 */
return new class extends Migration
{
    /**
     * Los estados de SEÑAL en los que la alerta sigue viva.
     *
     * Escrito aquí Y en PHP (`Alerta::ABIERTOS`), y una prueba los cruza: la
     * columna generada la evalúa MySQL y no puede leer la constante. Sin quien
     * las compare se separan el día que se agregue un estado, y el único
     * empezaría a permitir o impedir lo que no debe, sin fallar.
     */
    private const ABIERTOS = "'activa'";

    public function up(): void
    {
        $this->alertas();
        $this->corridas();
    }

    public function down(): void
    {
        Schema::dropIfExists('corridas_evaluacion');
        Schema::dropIfExists('alertas');
    }

    private function alertas(): void
    {
        if (Schema::hasTable('alertas')) {
            return;
        }

        Schema::create('alertas', function (Blueprint $t) {
            $t->id();

            $t->foreignId('matricula_oferta_id')->constrained('matricula_oferta');
            $t->foreignId('regla_id')->constrained('reglas_alerta');

            /*
             * La VERSIÓN con la que se levantó, y es lo que hace auditable el
             * módulo entero: dentro de dos años se puede contestar «con qué
             * umbral salió esto» aunque la regla haya cambiado tres veces.
             */
            $t->foreignId('regla_version_id')->constrained('reglas_alerta_versiones');

            /*
             * La categoría se COPIA de la regla en vez de leerse por relación.
             * No es desnormalizar por gusto: de ella depende quién ve el
             * detalle, y si la regla cambiara de categoría, las alertas ya
             * levantadas cambiarían de visibilidad de golpe — una señal
             * financiera pasaría a verse en abierto sin que nadie lo pidiera.
             */
            $t->foreignId('categoria_id')->constrained('categorias_senal');

            // Las señales POR MATERIA la traen; las del alumno, no.
            $t->foreignId('asignatura_grupo_id')->nullable()->constrained('asignatura_grupo');
            $t->foreignId('ciclo_id')->nullable()->constrained('ciclos');

            $t->string('severidad', 20);

            // Lo que dice el MOTOR.
            $t->string('estado_senal', 20)->default('activa');
            // Lo que dice una PERSONA.
            $t->string('estado_triage', 20)->default('nueva');

            $t->decimal('valor_observado', 12, 4)->nullable();
            $t->decimal('umbral', 12, 4)->nullable();
            $t->unsignedInteger('cobertura')->default(0);

            $t->date('ventana_desde')->nullable();
            $t->date('ventana_hasta')->nullable();

            /*
             * La EVIDENCIA, congelada.
             *
             * No se recalcula al mirarla: el dato de hoy ya no es el de
             * entonces, y una alerta que cambiara de explicación cada vez que se
             * abre no se podría discutir con nadie. Es el criterio del snapshot
             * de la liberación formativa y del emisor de una factura.
             */
            $t->json('evidencia');

            $t->timestamp('primera_vez_en');
            $t->timestamp('ultima_evaluacion_en');

            /*
             * Cuándo dejó de ser cierta, y con qué se comprobó.
             *
             * La evidencia de la MEJORA es lo que permite decirle a alguien «tu
             * asistencia subió del 68 al 84 %» en vez de que la alerta
             * desaparezca sin explicación.
             */
            $t->timestamp('cerrada_en')->nullable();
            $t->json('evidencia_cierre')->nullable();

            // El triage.
            $t->foreignId('motivo_descarte_id')->nullable()->constrained('motivos_descarte');
            $t->text('nota_triage')->nullable();
            $t->unsignedBigInteger('revisada_por')->nullable();
            $t->timestamp('revisada_en')->nullable();

            // El caso llega en la fase 5; la columna se agrega con él.

            $t->auditoria();

            /*
             * La llave de deduplicación: vale mientras la señal está ABIERTA y
             * NULL cuando se cerró. La materia entra porque el derecho a examen
             * se pierde materia por materia: dos alertas de asistencia de dos
             * materias distintas son dos problemas.
             */

            $t->index(['estado_senal', 'estado_triage', 'categoria_id'], 'alertas_bandeja');
            $t->index(['matricula_oferta_id', 'estado_senal'], 'alertas_del_alumno');
            $t->index(['regla_id', 'ultima_evaluacion_en'], 'alertas_de_la_regla');
        });

        DB::statement(
            'ALTER TABLE alertas ADD COLUMN clave_dedup VARCHAR(120) '
            ."AS (CASE WHEN deleted_at IS NULL AND estado_senal IN (".self::ABIERTOS.') '
            ."THEN CONCAT(matricula_oferta_id, ':', regla_id, ':', COALESCE(asignatura_grupo_id, 0)) END) STORED"
        );

        Schema::table('alertas', function (Blueprint $t) {
            $t->unique('clave_dedup', 'alerta_abierta_unica');
        });
    }

    /**
     * El registro de cada corrida del motor.
     *
     * ── Y lo que NO se guarda, que es más ──────────────────────────────────
     * La evaluación NEGATIVA no se persiste. Cinco mil alumnos por veinte reglas
     * por trescientos sesenta y cinco días son treinta y seis millones de filas
     * al año para almacenar «hoy tampoco», y es reproducible: con la regla, su
     * versión y la ventana se vuelve a calcular. Lo que queda es el CONTADOR por
     * corrida y la evidencia de lo que sí disparó.
     *
     * ── Una regla que revienta NO detiene a las demás ──────────────────────
     * Se aísla, se cuenta y su error queda aquí con el nombre de la regla. El
     * pedido lo exige y la razón es la de siempre: una regla mal configurada en
     * una escuela no puede dejar sin evaluar a las otras diecinueve.
     */
    private function corridas(): void
    {
        if (Schema::hasTable('corridas_evaluacion')) {
            return;
        }

        Schema::create('corridas_evaluacion', function (Blueprint $t) {
            $t->id();
            $t->timestamp('iniciada_en');
            $t->timestamp('terminada_en')->nullable();
            $t->string('disparo', 20)->default('programada');   // programada | manual | evento

            $t->unsignedInteger('matriculas_evaluadas')->default(0);
            $t->unsignedInteger('reglas_evaluadas')->default(0);
            $t->unsignedInteger('alertas_creadas')->default(0);
            $t->unsignedInteger('alertas_actualizadas')->default(0);
            $t->unsignedInteger('alertas_resueltas')->default(0);
            $t->unsignedInteger('alertas_obsoletas')->default(0);
            $t->unsignedInteger('sin_datos')->default(0);

            /*
             * Los errores POR REGLA, con su nombre. Sin el nombre habría que
             * cruzar un id contra una tabla para saber cuál falló, y esto lo
             * lee quien administra a las siete de la mañana.
             */
            $t->json('errores')->nullable();

            $t->unsignedInteger('milisegundos')->default(0);
            $t->unsignedBigInteger('corrida_por')->nullable();
            $t->timestamps();

            $t->index('iniciada_en');
        });
    }
};
