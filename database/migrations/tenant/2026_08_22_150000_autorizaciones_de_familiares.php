<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que un familiar autoriza o niega: salidas, uso de imagen, actividades.
 *
 * Es la única función del módulo 13 que no existía en ninguna forma. El vínculo
 * familiar, el portal y los avisos ya estaban construidos con otros nombres;
 * esto no.
 *
 * ── Una fila por VÍNCULO, no por alumno ────────────────────────────────────
 * Porque quien autoriza es una persona concreta y su respuesta es suya. Un
 * alumno con padre y madre registrados recibe dos filas, y la escuela ve
 * «respondió uno de dos» en vez de un sí ambiguo del que nadie se hace
 * responsable. Cuántas respuestas hacen falta NO lo decide el sistema: es
 * decisión de la escuela y depende del trámite, así que se muestra el conteo y
 * no se inventa un quórum.
 *
 * ── Por qué la autorización lleva su propio texto ──────────────────────────
 * La spec sólo ata la fila a un `tipo_autorizacion`, y con eso alcanza para un
 * consentimiento permanente («uso de imagen: sí»). No alcanza para el otro caso
 * real, que es el más frecuente: «la salida al museo del 5 de octubre». Con
 * `titulo`, `detalle` y `fecha_limite` la misma tabla sirve para los dos, en
 * vez de necesitar una segunda para los eventos con fecha.
 *
 * ── `concedida` en NULL es «todavía no contesta» ───────────────────────────
 * Tres estados con una columna: null pendiente, true concedida, false negada.
 * Un booleano no nulo obligaría a inventar un valor por omisión, y «no
 * contestó» acabaría contando como «dijo que no» — que legalmente no es lo
 * mismo.
 *
 * ── La respuesta se puede CAMBIAR ──────────────────────────────────────────
 * Un consentimiento de uso de imagen se revoca por derecho; negarlo aquí sería
 * ilegal. Lo que queda registrado del cambio es lo que da la auditoría del
 * proyecto (`updated_by`, `updated_at`) más `fecha_respuesta`. Si alguna
 * escuela necesita la CADENA completa de cambios y no sólo el último, eso es
 * una bitácora aparte y se agrega cuando alguien la pida: no se construye una
 * tabla por si acaso.
 */
return new class extends Migration
{
    /** Lo que casi toda escuela pide; el resto lo agrega ella. */
    private const SEMILLA = [
        ['salida', 'Salida escolar', 'Excursiones, visitas y actividades fuera del plantel.', 10],
        ['uso_imagen', 'Uso de imagen', 'Publicación de fotografías y video en medios de la escuela.', 20],
        ['actividad', 'Actividad especial', 'Talleres, competencias y actividades extracurriculares.', 30],
        ['tratamiento_medico', 'Atención médica de urgencia', 'Autorización para atención médica en caso de urgencia.', 40],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tipos_autorizacion')) {
            Schema::create('tipos_autorizacion', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 50)->unique();
                $table->string('nombre', 120);
                $table->string('descripcion', 255)->nullable();
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->auditoria();
            });
        }

        foreach (self::SEMILLA as [$clave, $nombre, $descripcion, $orden]) {
            DB::table('tipos_autorizacion')->updateOrInsert(
                ['clave' => $clave],
                [
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'orden' => $orden,
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        if (Schema::hasTable('autorizaciones')) {
            return;
        }

        Schema::create('autorizaciones', function (Blueprint $table) {
            $table->id();

            /*
             * Al VÍNCULO y no a la persona: la misma persona puede ser tutora
             * de dos alumnos, y una autorización es sobre uno de ellos. Con la
             * persona sola no se sabría de qué hijo se está hablando.
             */
            $table->foreignId('vinculo_familiar_id')->constrained('tutores_alumno')->cascadeOnDelete();
            $table->foreignId('tipo_autorizacion_id')->constrained('tipos_autorizacion');

            $table->string('titulo', 180);
            $table->string('detalle', 1000)->nullable();

            // Hasta cuándo se puede contestar. Null = permanente (uso de imagen).
            $table->date('fecha_limite')->nullable();

            // null = todavía no contesta. Ver el docblock.
            $table->boolean('concedida')->nullable();
            $table->timestamp('fecha_respuesta')->nullable();
            $table->string('comentario', 500)->nullable();

            $table->auditoria();

            /*
             * Lo que se consulta es «qué le falta contestar a este familiar»,
             * y para eso el índice tiene que EMPEZAR por el vínculo. Sin él, el
             * portal de cada padre recorre la tabla entera de la escuela.
             */
            $table->index(['vinculo_familiar_id', 'concedida']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizaciones');
        Schema::dropIfExists('tipos_autorizacion');
    }
};
