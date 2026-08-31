<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos escolares: la trayectoria administrativa de una matrícula.
 *
 * Función pedida por el cliente; NO está en la spec. Se diseña con los patrones
 * del proyecto en vez de inventar un dominio nuevo.
 *
 * ── Cuelga de la MATRÍCULA, no de la persona ───────────────────────────────
 * `matricula_oferta` ya ES la trayectoria: persona + oferta (programa, plan y
 * campus) + matrícula + generación + periodo + situación. Quien estudia dos
 * programas tiene dos, cada una con su historia. Igual que el historial
 * académico, la conducta y la cartera.
 *
 * ── Lo que NO se guarda, y por qué ─────────────────────────────────────────
 * Ni programa, ni plan, ni campus, ni la persona: todos salen de
 * `matricula_oferta->oferta`, y esa relación no cambia —cambiar de programa en
 * este sistema es OTRA matrícula, no una edición—. Guardarlos aquí sería
 * repetir un dato que ya tiene dueño, con el riesgo de que un día discrepen.
 *
 * ── Lo que SÍ se guarda: los pares «de → a» ────────────────────────────────
 * Sólo donde de verdad hay un cambio que el modelo operativo no conserva:
 *
 *   - `situacion_anterior_id` / `situacion_nueva_id`: la matrícula sólo guarda
 *     la situación VIGENTE, así que sin esto no se puede saber de dónde venía.
 *   - `oferta_anterior_id` / `oferta_nueva_id`: cubre de una vez el cambio de
 *     programa, de plan, de campus y de modalidad, porque los cuatro son
 *     atributos de la oferta. Un par por cada uno serían cuatro columnas
 *     diciendo lo mismo.
 *   - `grupo_anterior_id` / `grupo_nuevo_id`: hoy el alumno NO está atado a un
 *     grupo —se inscribe a materias y el grupo se deriva—, así que estas dos
 *     nacen para el movimiento MANUAL. Quedan listas para el día que exista la
 *     operación de cambiar de grupo.
 *   - `periodo_anterior` / `periodo_nuevo`: enteros, para promoción y
 *     repetición. `matricula_oferta.periodo_actual` sólo guarda el vigente.
 *
 * ── Inmutable ──────────────────────────────────────────────────────────────
 * No hay ruta que edite ni borre un movimiento. Un error se arregla
 * REGISTRANDO otro que lo corrige (`corrige_movimiento_id`), y los dos se
 * conservan. Es la misma decisión que el acta de corrección: un acta cerrada es
 * historia escolar y para cambiar un número se emite otra, no se reescribe.
 *
 * ── El ORIGEN distingue lo automático de lo capturado ──────────────────────
 * `origen` dice qué proceso lo generó. Sin eso, un movimiento capturado a mano
 * y uno emitido por la conversión de un aspirante se leen igual, y no se puede
 * auditar si el sistema está registrando lo que debe.
 *
 * ── `referencia` es lo que impide el duplicado ─────────────────────────────
 * Un proceso que corre dos veces —un doble clic, un reintento— no puede dejar
 * dos altas. La referencia identifica el HECHO que lo originó
 * («conversion:412») y va con índice único: la segunda vez choca contra la base
 * y no contra un `SELECT` previo que dos peticiones simultáneas pasan las dos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->catalogo();
        $this->movimientos();
        $this->sembrarTipos();
    }

    /**
     * El catálogo, con BANDERAS DE COMPORTAMIENTO y no claves cableadas.
     *
     * Qué campos pide el formulario lo dice el TIPO, no un `switch` por clave:
     * «cambio de grupo» enciende `pide_grupos`, «baja temporal» enciende
     * `pide_situacion` y `pide_motivo`. Así la escuela agrega «Cambio de
     * modalidad» desde la pantalla y el formulario le pide lo que corresponde,
     * sin tocar código — que es la regla 4 del proyecto.
     */
    private function catalogo(): void
    {
        if (Schema::hasTable('tipos_movimiento_escolar')) {
            return;
        }

        Schema::create('tipos_movimiento_escolar', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('clave', 40)->unique();
            $tabla->string('nombre', 80);
            $tabla->string('descripcion', 255)->nullable();

            /*
             * El color de la píldora en la línea de tiempo. Es DATO y no una
             * constante porque la escuela agrega tipos, y un tipo nuevo sin
             * color se vería como un hueco.
             */
            $tabla->string('color', 20)->default('gris');

            // Qué le pide el formulario a quien lo captura.
            $tabla->boolean('pide_ciclo')->default(false);
            $tabla->boolean('pide_grupos')->default(false);
            $tabla->boolean('pide_situacion')->default(false);
            $tabla->boolean('pide_oferta')->default(false);
            $tabla->boolean('pide_periodo')->default(false);
            $tabla->boolean('pide_motivo')->default(false);

            /*
             * Un tipo que el sistema emite solo no se ofrece en el formulario
             * manual: «Alta» la pone la conversión del aspirante, y dejar
             * capturarla a mano produciría dos altas de la misma matrícula.
             */
            $tabla->boolean('solo_automatico')->default(false);

            $tabla->unsignedSmallInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->auditoria();
        });
    }

    private function movimientos(): void
    {
        if (Schema::hasTable('movimientos_escolares')) {
            return;
        }

        Schema::create('movimientos_escolares', function (Blueprint $tabla) {
            $tabla->id();

            $tabla->foreignId('matricula_oferta_id')->constrained('matricula_oferta');
            $tabla->foreignId('tipo_id')->constrained('tipos_movimiento_escolar');

            /*
             * La fecha EFECTIVA —cuándo ocurrió— es distinta de `created_at`,
             * que es cuándo se capturó. Una baja del 3 de junio se registra el
             * 10; la trayectoria se ordena por la primera y la auditoría
             * responde con la segunda.
             */
            $tabla->date('fecha_efectiva');

            $tabla->foreignId('ciclo_id')->nullable()->constrained('ciclos');

            $tabla->foreignId('situacion_anterior_id')->nullable()->constrained('situaciones_alumno');
            $tabla->foreignId('situacion_nueva_id')->nullable()->constrained('situaciones_alumno');

            $tabla->foreignId('oferta_anterior_id')->nullable()->constrained('oferta');
            $tabla->foreignId('oferta_nueva_id')->nullable()->constrained('oferta');

            $tabla->foreignId('grupo_anterior_id')->nullable()->constrained('grupos');
            $tabla->foreignId('grupo_nuevo_id')->nullable()->constrained('grupos');

            $tabla->unsignedSmallInteger('periodo_anterior')->nullable();
            $tabla->unsignedSmallInteger('periodo_nuevo')->nullable();

            $tabla->string('motivo', 255)->nullable();
            $tabla->text('observaciones')->nullable();

            // Qué proceso lo generó: `manual`, `conversion_aspirante`, …
            $tabla->string('origen', 40)->default('manual');

            /*
             * El hecho que lo originó, para que un proceso repetido no deje dos
             * movimientos. Nullable porque un movimiento manual no tiene
             * proceso detrás.
             */
            $tabla->string('referencia', 80)->nullable();

            // La corrección APUNTA al movimiento que enmienda; ninguno se borra.
            $tabla->foreignId('corrige_movimiento_id')->nullable()->constrained('movimientos_escolares');

            $tabla->auditoria();

            /*
             * El índice de consulta: la pestaña siempre pregunta por una
             * matrícula ordenando por fecha. Empieza por `matricula_oferta_id`,
             * así que además sostiene su foránea.
             */
            $tabla->index(['matricula_oferta_id', 'fecha_efectiva'], 'movimientos_de_la_matricula');

            /*
             * Y el que impide el duplicado. MySQL permite repetir NULL en un
             * único, así que los manuales —sin referencia— no se estorban entre
             * sí, y dos corridas del mismo proceso sí chocan.
             */
            $tabla->unique(['matricula_oferta_id', 'referencia'], 'movimiento_no_se_repite');
        });
    }

    /**
     * Los tipos de arranque.
     *
     * Se siembran los que la escuela reconoce, con sus banderas. Los que hoy no
     * tienen operación en el sistema —cambio de grupo, de turno, de campus—
     * nacen igual: se capturan a mano hasta que exista el proceso que los
     * emita, y así el catálogo no se queda corto el día que se construya.
     */
    private function sembrarTipos(): void
    {
        $tipos = [
            ['alta', 'Alta', 'Ingreso de la matrícula al programa.', 'verde', ['solo_automatico' => true]],
            ['inscripcion', 'Inscripción', 'Primera inscripción a materias de un ciclo.', 'verde', ['pide_ciclo' => true]],
            ['reinscripcion', 'Reinscripción', 'Inscripción a las materias de un ciclo posterior.', 'azul', ['pide_ciclo' => true]],
            ['cambio_grupo', 'Cambio de grupo', 'Se le reasigna a otro grupo.', 'azul', ['pide_ciclo' => true, 'pide_grupos' => true, 'pide_motivo' => true]],
            ['cambio_turno', 'Cambio de turno', 'Cambia el turno en que cursa.', 'azul', ['pide_ciclo' => true, 'pide_grupos' => true, 'pide_motivo' => true]],
            ['cambio_campus', 'Cambio de campus', 'Se traslada a otro plantel.', 'azul', ['pide_oferta' => true, 'pide_motivo' => true]],
            ['cambio_programa', 'Cambio de programa académico', 'Se mueve a otro programa.', 'azul', ['pide_oferta' => true, 'pide_motivo' => true]],
            ['cambio_plan', 'Cambio de plan de estudios', 'Se le migra a otra versión del plan.', 'azul', ['pide_oferta' => true, 'pide_motivo' => true]],
            ['cambio_modalidad', 'Cambio de modalidad', 'Cambia la modalidad en que cursa.', 'azul', ['pide_oferta' => true, 'pide_motivo' => true]],
            ['promocion_periodo', 'Promoción de periodo', 'Avanza al siguiente periodo del plan.', 'verde', ['pide_periodo' => true]],
            ['repeticion_periodo', 'Repetición de periodo', 'Repite el periodo en curso.', 'naranja', ['pide_periodo' => true, 'pide_motivo' => true]],
            ['baja_temporal', 'Baja temporal', 'Interrumpe sus estudios con intención de volver.', 'naranja', ['pide_situacion' => true, 'pide_motivo' => true]],
            ['baja_definitiva', 'Baja definitiva', 'Deja el programa.', 'rojo', ['pide_situacion' => true, 'pide_motivo' => true]],
            ['reingreso', 'Reingreso', 'Se reincorpora tras una baja.', 'verde', ['pide_ciclo' => true, 'pide_situacion' => true]],
            ['egreso', 'Egreso', 'Concluye el plan de estudios.', 'verde', ['pide_situacion' => true]],
            ['titulacion', 'Titulación', 'Obtiene su título.', 'verde', ['pide_situacion' => true]],
            ['correccion', 'Corrección administrativa', 'Enmienda un movimiento anterior.', 'gris', ['pide_motivo' => true]],
            ['otro', 'Otro', 'Cualquier movimiento que la escuela necesite registrar.', 'gris', ['pide_motivo' => true]],
        ];

        $orden = 0;

        foreach ($tipos as [$clave, $nombre, $descripcion, $color, $banderas]) {
            $orden += 10;

            DB::table('tipos_movimiento_escolar')->updateOrInsert(
                ['clave' => $clave],
                array_merge([
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'color' => $color,
                    'orden' => $orden,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $banderas)
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_escolares');
        Schema::dropIfExists('tipos_movimiento_escolar');
    }
};
