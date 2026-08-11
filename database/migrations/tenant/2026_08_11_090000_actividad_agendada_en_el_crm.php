<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La bitácora del CRM aprende a AGENDAR, no sólo a registrar lo que ya pasó.
 *
 * ── El hueco ───────────────────────────────────────────────────────────────
 * `seguimientos_aspirante` guardaba contactos consumados: una llamada que se
 * hizo, un correo que se mandó. Lo único que miraba al futuro era
 * `proximo_contacto`, una FECHA SUELTA: no se podía decir qué se iba a hacer
 * ese día, ni quién, ni cerrarlo después diciendo cómo fue, ni cancelarlo. O
 * sea que el sistema sabía lo que pasó y no lo que falta por hacer, que es la
 * mitad del trabajo de quien da seguimiento.
 *
 * ── Una sola línea de tiempo, no dos tablas ───────────────────────────────
 * Una llamada agendada y una llamada hecha no son dos cosas distintas: son la
 * MISMA, en dos momentos de su vida. Separarlas en `tareas` y `seguimientos`
 * obligaría a mezclarlas otra vez en la pantalla —que es donde se lee el
 * historial— y a decidir en cada consulta de cuál de las dos sale cada
 * renglón. Aquí una entrada nace `agendado` o nace `realizado`, según se esté
 * planeando o registrando.
 *
 * ── Esto matiza el «append-only» de la tabla ──────────────────────────────
 * La migración original decía que un seguimiento no se edita porque «un
 * contacto ocurrió, y corregir la nota después no cambia que ocurrió». Sigue
 * valiendo para el HECHO: lo que se cierra no se reescribe. Pero un plan sí
 * cambia —se cumple, se cancela, se mueve de fecha—, y por eso la entrada
 * agendada admite exactamente tres desenlaces y ninguna edición más.
 *
 * ── `momento` pasa a nullable ─────────────────────────────────────────────
 * Era «cuándo ocurrió» y no admitía nulos porque todo lo registrado ya había
 * ocurrido. Una tarea agendada todavía no tiene ese dato: se llena al
 * cerrarla. Lo que viene de antes se marca `realizado` conservando su momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Catálogo y no enum: cada escuela nombra distinto los desenlaces de
         * una llamada, y de estos se sacan los reportes de «cuántos no
         * contestan». `cuenta_como_contacto` distingue hablar con la persona de
         * marcarle sin éxito: sin esa bandera, «se le llamó 6 veces» no dice si
         * alguien lo atendió alguna.
         */
        if (! Schema::hasTable('resultados_seguimiento')) {
            Schema::create('resultados_seguimiento', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 50)->unique();
                $table->string('nombre', 150);
                $table->boolean('cuenta_como_contacto')->default(true);
                // Un desenlace puede DAR POR PERDIDO al prospecto («no
                // interesado», «datos falsos»): la pantalla lo ofrece para
                // moverlo de etapa en el mismo gesto.
                $table->boolean('cierra_el_embudo')->default(false);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->auditoria();
            });
        }

        /*
         * Se siembran POCOS y ciertos, como el resto de catálogos del CRM: los
         * desenlaces que se repiten en cualquier escuela. Los suyos los agrega
         * cada una desde la pantalla.
         */
        $ahora = now();

        $desenlaces = [
            ['clave' => 'contesto', 'nombre' => 'Contestó', 'cuenta_como_contacto' => true, 'cierra_el_embudo' => false, 'orden' => 1],
            ['clave' => 'no_contesto', 'nombre' => 'No contestó', 'cuenta_como_contacto' => false, 'cierra_el_embudo' => false, 'orden' => 2],
            ['clave' => 'buzon', 'nombre' => 'Buzón de voz', 'cuenta_como_contacto' => false, 'cierra_el_embudo' => false, 'orden' => 3],
            ['clave' => 'numero_equivocado', 'nombre' => 'Número equivocado', 'cuenta_como_contacto' => false, 'cierra_el_embudo' => false, 'orden' => 4],
            ['clave' => 'pidio_informes', 'nombre' => 'Pidió informes', 'cuenta_como_contacto' => true, 'cierra_el_embudo' => false, 'orden' => 5],
            ['clave' => 'reprogramo', 'nombre' => 'Reprogramó', 'cuenta_como_contacto' => true, 'cierra_el_embudo' => false, 'orden' => 6],
            ['clave' => 'no_interesado', 'nombre' => 'No le interesa', 'cuenta_como_contacto' => true, 'cierra_el_embudo' => true, 'orden' => 7],
            ['clave' => 'ya_inscrito_otra', 'nombre' => 'Se inscribió en otra escuela', 'cuenta_como_contacto' => true, 'cierra_el_embudo' => true, 'orden' => 8],
        ];

        foreach ($desenlaces as $fila) {
            // Idempotente: un reintento tras un fallo parcial no debe chocar
            // contra su propio trabajo.
            if (DB::table('resultados_seguimiento')->where('clave', $fila['clave'])->exists()) {
                continue;
            }

            DB::table('resultados_seguimiento')->insert($fila + [
                'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        Schema::table('seguimientos_aspirante', function (Blueprint $table) {
            // Cuándo se quedó de hacer. Null = se registró algo ya ocurrido.
            $table->dateTime('programado_para')->nullable()->after('proximo_contacto');
            $table->string('estatus', 20)->default('realizado')->after('programado_para');
            $table->foreignId('resultado_id')->nullable()->after('estatus')
                ->constrained('resultados_seguimiento');
            // Lo que contestaron, con sus palabras. La `nota` dice qué se iba a
            // hacer; esto, cómo fue.
            $table->text('respuesta')->nullable()->after('resultado_id');
            $table->foreignId('cerrado_por')->nullable()->after('respuesta')
                ->constrained('personas');
            $table->timestamp('cerrado_en')->nullable()->after('cerrado_por');

            /*
             * El tablero de «qué me toca» pregunta por responsable + estatus +
             * fecha. Un índice y no tres: MySQL usa el prefijo izquierdo, así
             * que éste sirve también para «todo lo pendiente de alguien».
             */
            $table->index(['persona_id', 'estatus', 'programado_para'], 'seguimientos_pendientes_idx');
        });

        // `momento` deja de ser obligatorio: lo agendado aún no ocurrió.
        DB::statement('ALTER TABLE seguimientos_aspirante MODIFY momento TIMESTAMP NULL');

        // Lo que ya estaba registrado ocurrió: nace cerrado y con su fecha.
        DB::table('seguimientos_aspirante')->update(['estatus' => 'realizado']);
    }

    public function down(): void
    {
        Schema::table('seguimientos_aspirante', function (Blueprint $table) {
            $table->dropIndex('seguimientos_pendientes_idx');
            $table->dropConstrainedForeignId('resultado_id');
            $table->dropConstrainedForeignId('cerrado_por');
            $table->dropColumn(['programado_para', 'estatus', 'respuesta', 'cerrado_en']);
        });

        Schema::dropIfExists('resultados_seguimiento');
    }
};
