<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos de la escuela, fuera del calendario.
 *
 * ── Por qué no son eventos ─────────────────────────────────────────────────
 * Vivían como un tipo más de `eventos_calendario`, y no encajaban. Un evento
 * ocupa un día en la rejilla y se consulta cuando alguien mira el mes; un aviso
 * no es una fecha, es un MENSAJE que tiene que alcanzar a alguien, y su valor
 * está en que llegue —no en que esté archivado en un cuadrito del 14 de agosto—.
 *
 * De esa diferencia salen las tres cosas que un aviso necesita y un evento no:
 * prioridad, constancia de lectura y una vigencia propia («desde cuándo y hasta
 * cuándo se muestra»), que no tiene por qué coincidir con ninguna fecha del
 * calendario escolar.
 *
 * ── Los destinos se reusan tal cual ────────────────────────────────────────
 * `avisos_destinos` es gemela de `evento_destinos`: mismo `tipo` del enum
 * `DestinoEvento` —todos, rol, campus, nivel, carrera, plan, grupo, materia,
 * alumno— y mismo `destino_id`. Se copia la forma y NO se comparte la tabla:
 * una tabla con dos padres opcionales obliga a comprobar en cada consulta cuál
 * de los dos es, y ese es el tipo de ahorro que se paga durante años.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos', function (Blueprint $table) {
            $table->id();

            $table->string('titulo', 180);
            $table->text('cuerpo');

            /*
             * informativo | importante | critico — ver App\Enums\PrioridadAviso.
             * Sólo el crítico bloquea hasta confirmar la lectura.
             */
            $table->string('prioridad', 20)->default('informativo');

            /*
             * Desde cuándo y hasta cuándo se muestra.
             *
             * `publicado_desde` nulo = en cuanto se publique. `vigente_hasta`
             * nulo = hasta que alguien lo retire. Que un aviso caduque solo es
             * lo que evita el tablero lleno de mensajes de hace tres semestres,
             * que es como se deja de leer el tablero.
             */
            $table->dateTime('publicado_desde')->nullable();
            $table->dateTime('vigente_hasta')->nullable();

            // En borrador no lo ve nadie: se redacta hoy y se suelta el lunes.
            $table->boolean('publicado')->default(false);

            $table->auditoria();

            $table->index(['publicado', 'publicado_desde', 'vigente_hasta']);
        });

        Schema::create('avisos_destinos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aviso_id')->constrained('avisos')->cascadeOnDelete();

            // Del enum DestinoEvento. Con `todos`, `destino_id` va nulo.
            $table->string('tipo', 20);
            $table->unsignedBigInteger('destino_id')->nullable();

            $table->timestamps();

            $table->index(['tipo', 'destino_id']);
            $table->unique(['aviso_id', 'tipo', 'destino_id']);
        });

        Schema::create('avisos_lecturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aviso_id')->constrained('avisos')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            /*
             * Distingue haberlo CERRADO de haberlo CONFIRMADO.
             *
             * Cerrar un aviso importante es «ya lo vi, quítamelo»; confirmar uno
             * crítico es «declaro que lo leí», y eso es lo que la escuela puede
             * necesitar demostrar el día que alguien diga que nunca se enteró de
             * la suspensión de clases. Guardarlos en la misma columna
             * confundiría las dos cosas justo cuando importa distinguirlas.
             */
            $table->timestamp('visto_en')->nullable();
            $table->timestamp('confirmado_en')->nullable();

            $table->timestamps();

            $table->unique(['aviso_id', 'persona_id']);
            $table->index(['persona_id', 'confirmado_en']);
        });

        /*
         * Los avisos que ya estaban en el calendario se mudan.
         *
         * Se traen con sus destinos: la segmentación que alguien capturó no
         * tiene por qué volver a capturarse. Todos entran como `informativo`
         * —es lo que eran, un texto en el calendario— y conservan su estado de
         * publicación. La descripción del evento pasa a ser el cuerpo; si venía
         * vacía se repite el título, porque el cuerpo no admite nulo y un aviso
         * sin texto no diría nada.
         */
        foreach (DB::table('eventos_calendario')->where('tipo', 'aviso')->get() as $viejo) {
            $avisoId = DB::table('avisos')->insertGetId([
                'titulo' => $viejo->titulo,
                'cuerpo' => $viejo->descripcion ?: $viejo->titulo,
                'prioridad' => 'informativo',
                'publicado_desde' => $viejo->inicia_en,
                'vigente_hasta' => $viejo->termina_en,
                'publicado' => $viejo->publicado,
                'created_at' => $viejo->created_at,
                'updated_at' => now(),
                'created_by' => $viejo->created_by ?? null,
            ]);

            $destinos = DB::table('evento_destinos')->where('evento_id', $viejo->id)->get();

            foreach ($destinos as $d) {
                DB::table('avisos_destinos')->insert([
                    'aviso_id' => $avisoId,
                    'tipo' => $d->tipo,
                    'destino_id' => $d->destino_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Sin destinos capturados, el aviso era para todos: se hace
            // explícito en vez de dejarlo sin alcance y que no le llegue a nadie.
            if ($destinos->isEmpty()) {
                DB::table('avisos_destinos')->insert([
                    'aviso_id' => $avisoId,
                    'tipo' => 'todos',
                    'destino_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('evento_destinos')->where('evento_id', $viejo->id)->delete();
            DB::table('eventos_calendario')->where('id', $viejo->id)->delete();
        }

        /*
         * El calendario se queda con dos tipos: evento y feriado.
         *
         * Lo demás —receso, inicio y fin de ciclo, evaluación— pasa a `evento`.
         * No se pierde nada: siguen en la rejilla con su título y su fecha, sólo
         * que sin color propio.
         */
        DB::table('eventos_calendario')
            ->whereNotIn('tipo', ['evento', 'feriado'])
            ->update(['tipo' => 'evento']);
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_lecturas');
        Schema::dropIfExists('avisos_destinos');
        Schema::dropIfExists('avisos');
    }
};
