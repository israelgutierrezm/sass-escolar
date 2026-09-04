<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los casos de seguimiento: donde una señal se convierte en trabajo humano.
 *
 * ── El caso cuelga de la MATRÍCULA ─────────────────────────────────────────
 * Como el historial, la conducta, la cartera y los movimientos escolares. Quien
 * estudia dos programas tiene dos trayectorias y puede necesitar acompañamiento
 * en una y no en la otra; colgarlo de la persona obligaría a mezclarlas.
 *
 * ── OCHO estados y no los doce del pedido ──────────────────────────────────
 * Los cuatro primeros de aquella lista —`nueva`, `pendiente_revision`,
 * `validada`, `descartada`— son el triage de una SEÑAL y viven en `alertas`.
 * Aquí están los que describen el trabajo de una persona. La razón completa está
 * en `docs/plan-alertas-tempranas.md`: una señal no interviene, es cierta o dejó
 * de serlo, y con una sola máquina cerrar el caso obligaría a mentir sobre la
 * señal o a dejarlo abierto para no mentir.
 *
 * ── Reabrir CREA otro caso ─────────────────────────────────────────────────
 * Y no resucita el cerrado. El cierre es un hecho fechado con su resultado, y
 * reescribirlo borraría la medición de RECURRENCIA — que es justo lo que este
 * módulo existe para medir. Es el molde del acta de corrección y de la
 * liberación formativa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->contador();
        $this->casos();
        $this->pivoteDeAlertas();
        $this->equipo();
        $this->intervenciones();
        $this->tareas();
        $this->bitacoras();
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos_caso');
        Schema::dropIfExists('transiciones_caso');
        Schema::dropIfExists('tareas_caso');
        Schema::dropIfExists('intervenciones');
        Schema::dropIfExists('caso_equipo');
        Schema::dropIfExists('caso_alerta');
        Schema::dropIfExists('casos_permanencia');
        Schema::dropIfExists('contadores_caso');
    }

    /**
     * El contador del folio, SIN `id` autoincremental.
     *
     * Es la trampa documentada de `contadores_matricula`: un INSERT sobre una
     * tabla que tenga `id` autoincremental pisa `LAST_INSERT_ID()`, y el
     * incremento atómico deja de serlo — produciendo folios duplicados bajo
     * concurrencia, que es exactamente lo que este mecanismo existe para
     * impedir.
     */
    private function contador(): void
    {
        if (Schema::hasTable('contadores_caso')) {
            return;
        }

        Schema::create('contadores_caso', function (Blueprint $t) {
            $t->string('clave', 60)->primary();   // p. ej. «2026»
            $t->unsignedBigInteger('valor')->default(0);
            $t->timestamps();
        });
    }

    private function casos(): void
    {
        if (Schema::hasTable('casos_permanencia')) {
            return;
        }

        Schema::create('casos_permanencia', function (Blueprint $t) {
            $t->id();
            $t->string('folio', 40)->unique();

            $t->foreignId('matricula_oferta_id')->constrained('matricula_oferta');

            /*
             * El campus se COPIA de la oferta al abrir, y no se lee por
             * relación. Es lo que hace barato el recorte —la bandeja lo filtra
             * en cada consulta— y sobre todo lo hace ESTABLE: un alumno que
             * cambia de plantel no puede hacer que un caso cerrado desaparezca
             * del reporte del plantel donde de verdad se atendió.
             */
            $t->foreignId('campus_id')->nullable()->constrained('campus');
            $t->foreignId('ciclo_id')->nullable()->constrained('ciclos');

            $t->string('estado', 30)->default('abierto');
            $t->string('prioridad', 20)->default('media');

            /*
             * El nivel de riesgo AL ABRIR, congelado.
             *
             * El riesgo de hoy baja cuando el caso funciona —para eso se
             * interviene—, así que leyéndolo en vivo un caso resuelto se vería
             * como si nunca hubiera hecho falta. Congelarlo es lo único que
             * permite medir después si la intervención sirvió.
             */
            $t->foreignId('nivel_riesgo_apertura_id')->nullable()->constrained('niveles_riesgo');
            $t->unsignedSmallInteger('puntaje_apertura')->nullable();

            $t->unsignedBigInteger('responsable_id')->nullable();
            $t->unsignedBigInteger('abierto_por')->nullable();

            $t->timestamp('abierto_en');

            /*
             * Cuándo vence el compromiso de primer contacto, y cuándo se
             * cumplió. Dos columnas y no una: sin `primer_contacto_en` no se
             * puede contestar «cuánto tardamos», que es el indicador que dice si
             * esto sirve.
             */
            $t->timestamp('sla_vence_en')->nullable();
            $t->timestamp('primer_contacto_en')->nullable();

            $t->text('plan_intervencion')->nullable();

            $t->timestamp('cerrado_en')->nullable();
            $t->foreignId('motivo_cierre_id')->nullable()->constrained('motivos_cierre_caso');
            $t->text('resultado')->nullable();

            // La reapertura APUNTA al caso anterior en vez de resucitarlo.
            $t->foreignId('caso_origen_id')->nullable()->constrained('casos_permanencia');

            $t->auditoria();

            $t->index(['estado', 'responsable_id'], 'casos_por_responsable');
            $t->index(['campus_id', 'estado'], 'casos_por_campus');
            $t->index('sla_vence_en');
        });

        /*
         * UN caso abierto por matrícula, y lo sostiene la BASE.
         *
         * Con dos, las intervenciones se repartirían entre ellos y nadie sabría
         * dónde anotar la siguiente llamada. Un `SELECT` previo no basta —dos
         * coordinadores mirando la misma alerta lo pasan los dos—, y el único
         * pelado cerraría la puerta para siempre: nadie podría abrir un caso
         * nuevo el año que viene.
         *
         * Columna GENERADA, como `sesiones_caja` y `expedientes_proceso`. Y
         * `deleted_at` dentro del único NO serviría: MySQL da dos NULL por
         * distintos.
         */
        DB::statement(
            'ALTER TABLE casos_permanencia ADD COLUMN matricula_si_abierto BIGINT UNSIGNED '
            ."AS (CASE WHEN deleted_at IS NULL AND estado <> 'cerrado' THEN matricula_oferta_id END) STORED"
        );

        Schema::table('casos_permanencia', function (Blueprint $t) {
            $t->unique('matricula_si_abierto', 'caso_abierto_unico');
        });
    }

    /**
     * Qué señales reúne un caso.
     *
     * Un caso nace de UNA alerta validada y puede acumular más: alguien que ya
     * está siendo acompañado por su asistencia y al que además le sale una señal
     * académica no necesita un segundo caso, necesita que la nueva entre al que
     * ya tiene. Es lo que evita dos personas llamando al mismo alumno.
     */
    private function pivoteDeAlertas(): void
    {
        if (Schema::hasTable('caso_alerta')) {
            return;
        }

        Schema::create('caso_alerta', function (Blueprint $t) {
            $t->id();
            $t->foreignId('caso_id')->constrained('casos_permanencia');
            $t->foreignId('alerta_id')->constrained('alertas');
            $t->timestamp('sumada_en');
            $t->timestamps();

            // Una alerta pertenece a un caso, no a dos.
            $t->unique('alerta_id', 'alerta_en_un_solo_caso');
            $t->index('caso_id');
        });
    }

    /**
     * El equipo de apoyo.
     *
     * El RESPONSABLE es uno y vive en el caso; el equipo son N y viven aquí, con
     * su papel y sus fechas. **Estar en el equipo no es lo que da acceso**: eso
     * lo siguen decidiendo el permiso y el campus. Esta tabla dice quién
     * participa, no quién puede mirar — confundirlo convertiría una lista de
     * trabajo en un mecanismo de autorización paralelo.
     */
    private function equipo(): void
    {
        if (Schema::hasTable('caso_equipo')) {
            return;
        }

        Schema::create('caso_equipo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('caso_id')->constrained('casos_permanencia');
            $t->unsignedBigInteger('persona_id');
            $t->string('papel', 100)->nullable();
            $t->date('desde');
            $t->date('hasta')->nullable();
            $t->auditoria();

            $t->index(['caso_id', 'persona_id']);
        });
    }

    /**
     * Lo que se hizo: contactos, tutorías, canalizaciones.
     *
     * ── `visibilidad` es la capa de privacidad, y es de TRES valores ────────
     *  - `caso`: la ve cualquiera que alcance el caso.
     *  - `equipo`: sólo el responsable y quien esté en el equipo.
     *  - `reservada`: además hace falta `ver-notas-reservadas`.
     *
     * Y lo reservado **no viaja al frontend** para quien no lo alcanza: se
     * filtra en el servidor. Esconderlo con un `v-if` no es una defensa — es la
     * lección que este proyecto ya escribió con las notas de tutoría.
     */
    private function intervenciones(): void
    {
        if (Schema::hasTable('intervenciones')) {
            return;
        }

        Schema::create('intervenciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('caso_id')->constrained('casos_permanencia');
            $t->foreignId('tipo_intervencion_id')->constrained('tipos_intervencion');

            $t->text('objetivo')->nullable();
            $t->unsignedBigInteger('responsable_id')->nullable();
            $t->date('fecha');
            $t->string('canal', 40)->nullable();       // presencial, teléfono, correo…

            /*
             * Quién participó, como TEXTO libre en JSON y no como foráneas.
             * En una reunión hay gente que no tiene cuenta en el sistema —la
             * madre del alumno, alguien de una institución de salud— y
             * obligarlos a existir como `persona` llenaría el padrón de la
             * escuela con terceros.
             */
            $t->json('participantes')->nullable();

            $t->text('acuerdos')->nullable();
            $t->date('proxima_fecha')->nullable();
            $t->text('resultado')->nullable();

            $t->string('estado', 20)->default('realizada');   // programada | realizada | cancelada
            $t->string('visibilidad', 20)->default('caso');

            $t->string('evidencia_ruta', 400)->nullable();
            $t->string('evidencia_nombre', 255)->nullable();

            $t->auditoria();

            $t->index(['caso_id', 'fecha']);
        });
    }

    /**
     * Las tareas: lo que hace que el SLA tenga a quién reclamarle.
     *
     * Sin ellas, «hay que hablar con la mamá» vive en la cabeza de alguien y el
     * caso se queda parado sin que nada lo señale.
     */
    private function tareas(): void
    {
        if (Schema::hasTable('tareas_caso')) {
            return;
        }

        Schema::create('tareas_caso', function (Blueprint $t) {
            $t->id();
            $t->foreignId('caso_id')->constrained('casos_permanencia');
            $t->string('titulo', 255);
            $t->unsignedBigInteger('responsable_id')->nullable();
            $t->date('vence_en')->nullable();
            $t->timestamp('completada_en')->nullable();
            $t->text('resultado')->nullable();
            $t->auditoria();

            $t->index(['caso_id', 'completada_en']);
            $t->index('vence_en');
        });
    }

    /**
     * Las dos bitácoras: los movimientos y las consultas.
     */
    private function bitacoras(): void
    {
        if (! Schema::hasTable('transiciones_caso')) {
            /*
             * Inmutable y SIN `deleted_at`: es el registro de quién movió el
             * caso y cuándo. Una bitácora que se puede borrar no es una
             * bitácora. Mismo criterio que `transiciones_expediente`.
             */
            Schema::create('transiciones_caso', function (Blueprint $t) {
                $t->id();
                $t->foreignId('caso_id')->constrained('casos_permanencia');
                $t->string('estado_origen', 30)->nullable();   // null en la apertura
                $t->string('estado_destino', 30);
                $t->text('motivo')->nullable();
                $t->unsignedBigInteger('quien')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('momento');
                $t->timestamps();

                $t->index(['caso_id', 'momento']);
            });
        }

        if (Schema::hasTable('accesos_caso')) {
            return;
        }

        /*
         * La bitácora de CONSULTA, calcada de `accesos_bitacora_tutoria`.
         *
         * Lo que hay dentro de un caso son conversaciones sobre la vida de
         * alguien. El permiso decide quién PUEDE entrar; esto deja rastro de
         * quién entró, que es lo que sirve el día que el contenido circula por
         * la escuela y hay que averiguar por dónde salió.
         *
         * Se registra la CONSULTA y no el contenido: cuántas intervenciones se
         * mostraron y cuántas quedaron reservadas. Una auditoría que copie lo
         * vigilado multiplica el problema que intenta resolver.
         *
         * Y se ENSEÑA a quien mira, como allá: esconderla en una tabla que sólo
         * consulta un administrador la vuelve un trámite forense; a la vista,
         * es lo que de verdad disuade.
         */
        Schema::create('accesos_caso', function (Blueprint $t) {
            $t->id();
            $t->foreignId('caso_id')->constrained('casos_permanencia');
            $t->unsignedBigInteger('persona_id')->nullable();
            $t->unsignedSmallInteger('intervenciones_vistas')->default(0);
            $t->unsignedSmallInteger('reservadas_ocultas')->default(0);
            $t->string('ip', 45)->nullable();
            $t->timestamp('creado_en');

            $t->index(['caso_id', 'creado_en']);
        });
    }
};
