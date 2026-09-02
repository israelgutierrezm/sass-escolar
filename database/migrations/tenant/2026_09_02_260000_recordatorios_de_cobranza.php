<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranza: avisarle a quien debe, antes y después de que venza.
 *
 * ── Lo que hoy no pasa ─────────────────────────────────────────────────────
 * La deuda existe, el estatus de moroso existe y el bloqueo existe. Lo que no
 * existe es que alguien se ENTERE: hoy el adeudo sólo asoma si la familia entra
 * al portal a mirarlo, y nadie entra «por si acaso». La escuela descubre el
 * atraso el día que el alumno choca contra el bloqueo, que es el peor momento
 * para los dos.
 *
 * ── La escalera es CONFIGURABLE, no cableada ───────────────────────────────
 * Cuándo se recuerda y con qué palabras es una decisión de cada escuela: hay
 * quien avisa tres días antes y quien no molesta hasta el mes. Un
 * `if ($dias === 3)` significaría que cambiar el tono exige programar. Aquí
 * cada peldaño es una fila que HACE algo —agregar una la agrega a la escalera—,
 * que es la prueba de que esto sí es catálogo.
 *
 * ── `dias` va CON SIGNO ────────────────────────────────────────────────────
 * Negativo antes de vencer, cero el día mismo, positivo después. «Antes» y
 * «después» son el mismo eje: con dos columnas —tipo y días— habría estados
 * imposibles («antes» con −5) y el orden de la escalera dejaría de ser un
 * `ORDER BY`.
 *
 * ── Nacen APAGADAS ─────────────────────────────────────────────────────────
 * Se siembran tres con un texto razonable, pero sin encender. Encendidas, la
 * escuela que migra empieza a mandarle avisos de deuda a las familias el primer
 * día, con datos que todavía está cargando. Mismo criterio que la publicación
 * automática de grabaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reglas_recordatorio_cobranza')) {
            Schema::create('reglas_recordatorio_cobranza', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->string('nombre', 120);
                // Con signo. Ver la nota de arriba.
                $tabla->smallInteger('dias');
                $tabla->string('titulo', 180);
                $tabla->text('cuerpo');
                $tabla->string('prioridad', 20)->default('informativo');
                $tabla->unsignedSmallInteger('dias_vigente')->default(15);
                $tabla->boolean('activo')->default(false);
                $tabla->auditoria();

                $tabla->unique(['dias']);
            });
        }

        if (! Schema::hasTable('recordatorios_cobranza')) {
            Schema::create('recordatorios_cobranza', function (Blueprint $tabla) {
                $tabla->id();
                $tabla->foreignId('adeudo_id')->constrained('adeudos')->cascadeOnDelete();
                $tabla->foreignId('regla_id')->constrained('reglas_recordatorio_cobranza');
                // El aviso que se levantó. Nullable porque un aviso se puede
                // borrar y el rastro de que YA se recordó tiene que sobrevivir:
                // si no, el comando volvería a avisar al día siguiente.
                $tabla->foreignId('aviso_id')->nullable()->constrained('avisos')->nullOnDelete();
                $tabla->timestamp('emitido_en');
                $tabla->auditoria();

                /*
                 * Un peldaño se recuerda UNA vez por cargo. Sin este único, el
                 * comando diario volvería a avisar cada mañana mientras el cargo
                 * siga vencido —y un recordatorio que llega treinta días
                 * seguidos deja de leerse al tercero—. Y no basta un `SELECT`
                 * previo: dos corridas simultáneas lo pasan las dos.
                 */
                $tabla->unique(['adeudo_id', 'regla_id']);
            });
        }

        // Tres peldaños de ejemplo, APAGADOS. Ver la nota de arriba.
        if (DB::table('reglas_recordatorio_cobranza')->count() === 0) {
            $ahora = now();

            DB::table('reglas_recordatorio_cobranza')->insert([
                [
                    'nombre' => 'Aviso previo',
                    'dias' => -3,
                    'titulo' => 'Tu pago vence el {vence}',
                    'cuerpo' => "Hola {alumno}:\n\nTe recordamos que tienes {cargos} cargo(s) por un total de {monto} con vencimiento el {vence}.\n\nPuedes consultarlos y pagarlos desde tu estado de cuenta.",
                    'prioridad' => 'informativo',
                    'dias_vigente' => 7,
                    'activo' => false,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ],
                [
                    'nombre' => 'El día del vencimiento',
                    'dias' => 0,
                    'titulo' => 'Hoy vence tu pago',
                    'cuerpo' => "Hola {alumno}:\n\nHoy vence(n) {cargos} cargo(s) por un total de {monto}.\n\nSi ya lo pagaste, no hace falta que hagas nada.",
                    'prioridad' => 'informativo',
                    'dias_vigente' => 7,
                    'activo' => false,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ],
                [
                    'nombre' => 'Ocho días de atraso',
                    'dias' => 8,
                    'titulo' => 'Tienes un pago vencido',
                    'cuerpo' => "Hola {alumno}:\n\nTienes {cargos} cargo(s) vencidos por un total de {monto}, con {dias} día(s) de atraso.\n\nSi no puedes cubrirlo de una vez, acércate a la escuela: se puede acordar un convenio de pago.",
                    'prioridad' => 'importante',
                    'dias_vigente' => 20,
                    'activo' => false,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recordatorios_cobranza');
        Schema::dropIfExists('reglas_recordatorio_cobranza');
    }
};
