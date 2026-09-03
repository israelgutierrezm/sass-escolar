<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que el alumno HACE: horas, informes y evaluaciones.
 *
 * ── Tres tablas para tres hechos distintos ────────────────────────────────
 *  1. `bitacora_horas` — cada jornada, con quién la capturó y quién la aprobó.
 *  2. `informes_proceso` — lo que entrega, con su fecha límite.
 *  3. `evaluaciones_proceso` — qué opinan de él el supervisor, el coordinador
 *     y él mismo.
 *
 * ── Los MINUTOS se guardan, no las horas ──────────────────────────────────
 * Una jornada de 9:00 a 13:30 son 4.5 horas, y en decimal eso se redondea. Con
 * minutos enteros la suma es exacta, y «cuántas horas lleva» es una división al
 * mirarlo — al revés, el error se acumula jornada a jornada y a las 480 horas
 * nadie sabe de dónde salió la diferencia. Es la misma razón por la que el
 * dinero de este sistema no vive en floats.
 *
 * ── `minutos_totales` es una columna GENERADA ─────────────────────────────
 * Calculada por MySQL a partir de las horas y el descanso, así que no puede
 * decir algo distinto de sus propios datos. Escrita por PHP, bastaría con que
 * un camino la olvidara —una corrección, una carga masiva— para que un
 * expediente sumara horas que nadie trabajó.
 *
 * ── La GEOLOCALIZACIÓN es nullable y nunca obligatoria ────────────────────
 * Instrucción explícita del cliente. Las columnas existen para la escuela que
 * la encienda, y el ajuste nace APAGADO: pedir la ubicación de un estudiante
 * cada vez que registra una jornada es rastrearlo, y esa decisión no puede ser
 * un efecto secundario de haber configurado el módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->horas();
        $this->informes();
        $this->evaluaciones();
    }

    private function horas(): void
    {
        if (Schema::hasTable('bitacora_horas')) {
            return;
        }

        Schema::create('bitacora_horas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes_proceso')->cascadeOnDelete();

            $tabla->date('fecha');
            $tabla->time('hora_inicio');
            $tabla->time('hora_fin');
            $tabla->unsignedSmallInteger('minutos_descanso')->default(0);

            $tabla->text('actividad');
            $tabla->foreignId('modalidad_id')->nullable()->constrained('modalidades_proceso');

            // Del disco privado. Una foto del registro de entrada, la firma del
            // supervisor… lo que la escuela pida.
            $tabla->string('evidencia_ruta', 400)->nullable();

            /*
             * Opcionales SIEMPRE. Y con precisión de metros y no de
             * centímetros: `decimal(10,7)` sitúa a alguien en un radio de un
             * centímetro, que es más de lo que hace falta para saber que
             * estuvo en el edificio correcto.
             */
            $tabla->decimal('latitud', 9, 5)->nullable();
            $tabla->decimal('longitud', 9, 5)->nullable();

            $tabla->string('estado', 20)->default('capturada');
            $tabla->unsignedBigInteger('capturada_por')->nullable();
            $tabla->unsignedBigInteger('aprobada_por')->nullable();
            $tabla->timestamp('aprobada_en')->nullable();
            $tabla->text('motivo_rechazo')->nullable();

            $tabla->auditoria();

            // Por él se pregunta siempre: el traslape, la suma y la pantalla.
            $tabla->index(['expediente_id', 'fecha']);
        });

        /*
         * Los minutos, calculados por MySQL. Va con `DB::statement` porque el
         * constructor de Laravel no expresa una generada con aritmética de
         * horas, y STORED —no VIRTUAL— para poder sumarla sin recalcular la
         * expresión en cada fila.
         *
         * `TIMESTAMPDIFF` sobre dos TIME devuelve minutos con signo; el
         * `GREATEST(0, …)` es una red por si alguna vez entra una fila con el
         * fin antes del inicio: la validación lo impide, pero una columna que
         * puede quedar negativa restaría horas trabajadas.
         */
        Schema::getConnection()->statement(
            'ALTER TABLE bitacora_horas ADD COLUMN minutos_totales INT '
            .'AS (GREATEST(0, TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin) - minutos_descanso)) STORED'
        );
    }

    private function informes(): void
    {
        if (Schema::hasTable('informes_proceso')) {
            return;
        }

        Schema::create('informes_proceso', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes_proceso')->cascadeOnDelete();
            $tabla->foreignId('tipo_informe_id')->constrained('tipos_informe_proceso');

            /*
             * El NÚMERO del informe dentro de su tipo: el primer parcial, el
             * segundo… Sin él, «te falta el segundo» no se puede decir, y dos
             * entregas del mismo tipo serían indistinguibles.
             */
            $tabla->unsignedSmallInteger('numero')->default(1);

            $tabla->date('fecha_limite')->nullable();
            $tabla->timestamp('entregado_en')->nullable();
            $tabla->string('archivo_ruta', 400)->nullable();
            $tabla->string('nombre_original', 255)->nullable();

            $tabla->string('estado', 20)->default('pendiente');
            $tabla->text('retroalimentacion')->nullable();
            $tabla->unsignedBigInteger('revisado_por')->nullable();
            $tabla->timestamp('revisado_en')->nullable();

            $tabla->auditoria();

            // Un informe por (tipo, número): el segundo parcial es UNO.
            $tabla->unique(['expediente_id', 'tipo_informe_id', 'numero'], 'informe_del_expediente_unico');
        });
    }

    private function evaluaciones(): void
    {
        if (Schema::hasTable('evaluaciones_proceso')) {
            return;
        }

        Schema::create('evaluaciones_proceso', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('expediente_id')->constrained('expedientes_proceso')->cascadeOnDelete();

            // Quién opina: supervisor, coordinador o el propio estudiante. Es
            // una columna y no un catálogo porque cada valor es una RAMA —quién
            // la puede capturar y sobre quién habla—, así que una fila nueva no
            // haría nada. Mismo argumento que `tipos_actividad`.
            $tabla->string('origen', 20);

            /*
             * La rúbrica se REUSA de `rubricas`, la del LMS. Estrenar un
             * segundo motor de criterios y niveles daría dos sitios donde
             * definir lo mismo, y el día que uno gane una función el otro se
             * quedaría atrás. Nullable: una evaluación puede ser sólo un
             * puntaje y un comentario.
             */
            $tabla->foreignId('rubrica_id')->nullable()->constrained('rubricas');

            $tabla->decimal('puntaje', 6, 2)->nullable();

            /*
             * Lo respondido, congelado. Guarda el nivel elegido por criterio
             * CON su texto y sus puntos: la rúbrica se puede editar después, y
             * una evaluación que se relea contra la rúbrica de hoy diría algo
             * que el supervisor nunca firmó. Mismo criterio que el emisor
             * congelado en la factura.
             */
            $tabla->json('respuestas')->nullable();

            $tabla->text('comentarios')->nullable();
            $tabla->timestamp('firmada_en')->nullable();
            $tabla->string('archivo_ruta', 400)->nullable();
            $tabla->unsignedBigInteger('capturada_por')->nullable();

            $tabla->auditoria();

            // Una por origen: el supervisor evalúa UNA vez. Corregirla es
            // editarla, no acumular otra que contradiga a la primera.
            $tabla->unique(['expediente_id', 'origen'], 'evaluacion_del_expediente_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluaciones_proceso');
        Schema::dropIfExists('informes_proceso');
        Schema::dropIfExists('bitacora_horas');
    }
};
