<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 12 · Movilidad, primera rebanada — convenios, convocatorias y
 * postulaciones.
 *
 * ── `direccion` es COLUMNA y no catálogo, al revés de la spec ─────────────
 * Saliente y entrante no son dos filas de una lista: son dos caminos distintos
 * del código. Un saliente es una MATRÍCULA nuestra que se va y a la que después
 * se le revalidan materias; un entrante es una persona ajena que llega y a la
 * que nunca se le escribe historial académico. Una fila nueva en un catálogo no
 * enseñaría al sistema un tercer camino, así que sería configurable de mentiras.
 *
 * ── Las etapas SÍ son catálogo, con banderas ──────────────────────────────
 * Y las banderas son lo que el código lee: `acepta` marca la etapa que consume
 * cupo y habilita abrir la estancia; `es_final` la que cierra el proceso. Es la
 * misma lección de `etapas_postulacion` en la bolsa de trabajo y de
 * `entra_a_nomina` en RH — preguntar por `clave = 'aceptado'` funciona hoy y
 * deja de funcionar en silencio el día que la escuela renombre su embudo.
 *
 * ── Sin carreras señaladas, el convenio cubre TODAS ───────────────────────
 * Misma decisión que las vacantes: exigir al menos una obligaría a palomear las
 * veinte carreras cada vez, y la mayoría de los convenios marco son generales.
 * Lo que sí hay que hacer es decirlo con palabras en la pantalla.
 *
 * ── Vencido ≠ suspendido ──────────────────────────────────────────────────
 * `situaciones_convenio` NO siembra «vencido»: eso lo dice `vigente_hasta`, y
 * con las dos cosas un convenio podría decir «vigente» con la fecha pasada y
 * nadie sabría cuál manda. Es la misma trampa que ya mordió en la bolsa.
 *
 * ── Exactamente UN titular por postulación ────────────────────────────────
 * Matrícula (saliente) o persona externa (entrante), nunca las dos ni ninguna,
 * con CHECK en MySQL. Es el mismo mecanismo que `adeudos` con su titular dual.
 *
 * ── Los requisitos documentales se REUSAN ─────────────────────────────────
 * `convocatoria_requisitos` apunta a `documentos_requeridos` del módulo de
 * admisiones. La spec lo pedía así y tiene razón: una segunda lista de papeles
 * sería un segundo lugar donde configurar «identificación oficial».
 */
return new class extends Migration
{
    private const TIPOS_INSTITUCION = [
        ['universidad', 'Universidad', 10],
        ['tecnologico', 'Instituto tecnológico', 20],
        ['centro_investigacion', 'Centro de investigación', 30],
        ['empresa', 'Empresa u organismo', 40],
    ];

    private const TIPOS_CONVENIO = [
        ['movilidad', 'Movilidad estudiantil', 10],
        ['doble_titulacion', 'Doble titulación', 20],
        ['investigacion', 'Investigación', 30],
        ['practicas', 'Prácticas profesionales', 40],
    ];

    /** clave, nombre, permite convocar, orden. Sin «vencido»: lo dice la fecha. */
    private const SITUACIONES_CONVENIO = [
        ['vigente', 'Vigente', true, 10],
        ['suspendido', 'Suspendido', false, 20],
        ['en_firma', 'En firma', false, 30],
    ];

    /** clave, nombre, acepta, es final, orden. */
    private const ETAPAS = [
        ['postulado', 'Postulado', false, false, 10],
        ['en_evaluacion', 'En evaluación', false, false, 20],
        ['aceptado', 'Aceptado', true, false, 30],
        ['en_curso', 'En curso', true, false, 40],
        ['concluido', 'Concluido', true, true, 50],
        ['rechazado', 'Rechazado', false, true, 60],
        ['desistio', 'Desistió', false, true, 70],
    ];

    public function up(): void
    {
        $this->catalogos();
        $this->convenios();
        $this->convocatorias();
        $this->postulaciones();
    }

    public function down(): void
    {
        foreach ([
            'estancias', 'postulaciones_movilidad', 'convocatoria_requisitos',
            'convocatorias_movilidad', 'convenio_carreras', 'convenios',
            'instituciones_aliadas', 'etapas_movilidad', 'situaciones_convenio',
            'tipos_convenio', 'tipos_institucion',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }

    private function catalogos(): void
    {
        foreach (['tipos_institucion', 'tipos_convenio'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                continue;
            }

            Schema::create($tabla, function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);
                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        if (! Schema::hasTable('situaciones_convenio')) {
            Schema::create('situaciones_convenio', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);

                // Lo que el código lee para saber si se puede convocar. La clave
                // no sirve: mañana la escuela agrega «en renovación».
                $t->boolean('permite_convocar')->default(false);

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        if (! Schema::hasTable('etapas_movilidad')) {
            Schema::create('etapas_movilidad', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);

                /*
                 * Dos banderas independientes: «Concluido» acepta y cierra;
                 * «Rechazado» cierra y no acepta. `acepta` es la que consume
                 * cupo y habilita abrir la estancia.
                 */
                $t->boolean('acepta')->default(false);
                $t->boolean('es_final')->default(false);

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        foreach ([
            'tipos_institucion' => self::TIPOS_INSTITUCION,
            'tipos_convenio' => self::TIPOS_CONVENIO,
        ] as $tabla => $filas) {
            foreach ($filas as [$clave, $nombre, $orden]) {
                DB::table($tabla)->updateOrInsert(['clave' => $clave], [
                    'nombre' => $nombre, 'orden' => $orden, 'activo' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (self::SITUACIONES_CONVENIO as [$clave, $nombre, $convoca, $orden]) {
            DB::table('situaciones_convenio')->updateOrInsert(['clave' => $clave], [
                'nombre' => $nombre, 'permite_convocar' => $convoca, 'orden' => $orden,
                'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (self::ETAPAS as [$clave, $nombre, $acepta, $final, $orden]) {
            DB::table('etapas_movilidad')->updateOrInsert(['clave' => $clave], [
                'nombre' => $nombre, 'acepta' => $acepta, 'es_final' => $final,
                'orden' => $orden, 'activo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function convenios(): void
    {
        if (! Schema::hasTable('instituciones_aliadas')) {
            Schema::create('instituciones_aliadas', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 255);

                /*
                 * `paises` vive en la base CENTRAL: se guarda el id SIN foránea,
                 * que es la regla del proyecto para los catálogos universales.
                 * La relación Eloquent sí resuelve, porque el modelo landlord
                 * usa `CentralConnection`.
                 */
                $t->unsignedBigInteger('pais_id')->nullable();

                $t->string('ciudad', 120)->nullable();
                $t->foreignId('tipo_id')->constrained('tipos_institucion');
                $t->string('sitio_web', 255)->nullable();
                $t->boolean('activa')->default(true);
                $t->auditoria();
            });
        }

        if (Schema::hasTable('convenios')) {
            return;
        }

        Schema::create('convenios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('institucion_aliada_id')->constrained('instituciones_aliadas');
            $t->foreignId('tipo_convenio_id')->constrained('tipos_convenio');

            // El folio es del papel firmado: no se repite.
            $t->string('folio', 50)->unique();

            $t->date('vigente_desde');
            // Null = sin fecha de término. Vencido lo dice esta columna, no el
            // catálogo de situaciones.
            $t->date('vigente_hasta')->nullable();

            $t->foreignId('situacion_id')->constrained('situaciones_convenio');
            $t->string('documento_ruta', 500)->nullable();
            $t->text('notas')->nullable();
            $t->auditoria();

            $t->index(['vigente_desde', 'vigente_hasta']);
        });

        Schema::create('convenio_carreras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();
            $t->foreignId('carrera_id')->constrained('carreras')->cascadeOnDelete();
            $t->auditoria();
            $t->unique(['convenio_id', 'carrera_id']);
        });
    }

    private function convocatorias(): void
    {
        if (Schema::hasTable('convocatorias_movilidad')) {
            return;
        }

        Schema::create('convocatorias_movilidad', function (Blueprint $t) {
            $t->id();
            $t->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();
            $t->string('titulo', 200);

            /*
             * Columna y no catálogo. Ver el docblock: saliente y entrante son
             * dos caminos del código, no dos filas de una lista.
             */
            $t->string('direccion', 20);

            $t->string('periodo', 50);
            $t->unsignedSmallInteger('cupo');

            // Requisito de promedio. Null = no lo pide.
            $t->decimal('promedio_minimo', 4, 2)->nullable();

            $t->date('fecha_apertura');
            $t->date('fecha_cierre');
            $t->text('descripcion')->nullable();
            $t->auditoria();

            $t->index(['fecha_apertura', 'fecha_cierre']);
        });

        // Se REUSAN los documentos requeridos de admisiones: una segunda lista
        // de papeles sería un segundo lugar donde configurar lo mismo.
        Schema::create('convocatoria_requisitos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('convocatoria_id')->constrained('convocatorias_movilidad')->cascadeOnDelete();
            $t->foreignId('documento_requerido_id')->constrained('documentos_requeridos');
            $t->auditoria();
            $t->unique(['convocatoria_id', 'documento_requerido_id'], 'requisito_unico');
        });
    }

    private function postulaciones(): void
    {
        if (! Schema::hasTable('postulaciones_movilidad')) {
            Schema::create('postulaciones_movilidad', function (Blueprint $t) {
                $t->id();
                $t->foreignId('convocatoria_id')->constrained('convocatorias_movilidad')->cascadeOnDelete();

                // Titular DUAL: exactamente uno de los dos. Mismo mecanismo que
                // `adeudos`, con CHECK abajo.
                /*
                 * SIN acción referencial, y no por gusto: MySQL rechaza con el
                 * error 3823 una columna que participa en un CHECK y además
                 * tiene `ON DELETE SET NULL`. Y `nullOnDelete` sería además lo
                 * incorrecto: dejaría la postulación sin NINGÚN titular, que es
                 * justo lo que el CHECK impide. Igual que `adeudos`.
                 */
                $t->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');
                $t->foreignId('persona_externa_id')->nullable()->constrained('personas');

                $t->foreignId('etapa_id')->constrained('etapas_movilidad');

                /*
                 * El promedio con el que se le acepta, CONGELADO al postularse.
                 * Se calcula del historial y no se captura: un número tecleado
                 * es un número que alguien puede acomodar. Y se congela porque
                 * el promedio de hoy no es con el que se le evaluó.
                 */
                $t->decimal('promedio_acreditado', 4, 2)->nullable();

                $t->timestamp('fecha_postulacion');
                $t->text('notas')->nullable();
                $t->auditoria();

                $t->unique(['convocatoria_id', 'matricula_oferta_id'], 'postulacion_unica_alumno');
                $t->unique(['convocatoria_id', 'persona_externa_id'], 'postulacion_unica_externo');
                $t->index(['convocatoria_id', 'etapa_id']);
            });

        }

        // El CHECK lo pone la migración siguiente, y a propósito: metido aquí
        // dentro, un reintento tras un fallo parcial se lo saltaría para
        // siempre porque la tabla ya existiría. Comprobar antes de actuar es
        // por PIEZA, no por bloque.

        if (Schema::hasTable('estancias')) {
            return;
        }

        /*
         * La estancia es el periodo EFECTIVO del intercambio.
         *
         * Una por postulación —único—: dos estancias del mismo intercambio
         * serían el mismo hecho contado dos veces, y la revalidación no sabría
         * de cuál cuelga.
         *
         * La institución NO se repite aquí: sale por
         * postulación → convocatoria → convenio → institución. Copiarla crearía
         * la posibilidad de que difieran.
         */
        Schema::create('estancias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('postulacion_id')->unique()->constrained('postulaciones_movilidad')->cascadeOnDelete();
            $t->date('fecha_inicio');
            $t->date('fecha_fin')->nullable();
            $t->date('concluida_en')->nullable();
            $t->text('notas')->nullable();
            $t->auditoria();
        });
    }
};
