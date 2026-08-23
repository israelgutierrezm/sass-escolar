<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 12 · Movilidad, segunda rebanada — las revalidaciones.
 *
 * Es el gesto delicado del módulo: al aprobarse, ESCRIBE en el historial
 * académico, que es lo más sensible del sistema.
 *
 * ── El asiento NO necesita una columna «origen», y es el hallazgo ─────────
 * La spec pedía «una bandera de origen movilidad» en `historial`. No hace
 * falta: el mecanismo ya existe y además es el OFICIAL.
 *
 *   - `tipos_evaluacion` ya trae `revalidacion` desde la fase 2.
 *   - `observaciones_asignatura` —catálogo de la SEP— ya trae
 *     «REVALIDACIÓN DE ESTUDIOS», y ése es el valor que viaja en el XML del
 *     certificado.
 *
 * Inventar una columna propia habría dejado el dato fuera del certificado y
 * habría creado una segunda forma de decir lo mismo. Se usa la que la SEP ya
 * reconoce.
 *
 * ── El dictamen es catálogo, con la bandera que importa ───────────────────
 * `asienta` dice cuál de ellos escribe en el historial. Aprobada asienta;
 * rechazada no; «parcial» —que la spec menciona— es una decisión de cada
 * escuela y por eso es una fila que ella configura, no un caso del código.
 *
 * ── Una revalidación por materia y estancia ───────────────────────────────
 * Único sobre (estancia, plan_materia). Dos serían dos asientos de la misma
 * materia, y `HistorialDelAlumno` toma el MEJOR intento por materia para los
 * totales: el alumno acabaría con créditos de más.
 *
 * ── Se guarda lo que dijo el DESTINO, tal cual ────────────────────────────
 * `materia_externa` y `calificacion_externa` son texto: allá la materia se
 * llama de otro modo y la calificación puede venir en otra escala —«B+»,
 * «16/20»—. Convertirla es un juicio humano, y por eso
 * `calificacion_equivalente` se captura y no se calcula: no hay tabla de
 * conversión universal y fabricar una sería inventarle una nota a alguien.
 */
return new class extends Migration
{
    /** clave, nombre, asienta en el historial, orden. */
    private const DICTAMENES = [
        ['aprobada', 'Aprobada', true, 10],
        ['rechazada', 'Rechazada', false, 20],
        ['pendiente', 'Pendiente de dictamen', false, 30],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('dictamenes_revalidacion')) {
            Schema::create('dictamenes_revalidacion', function (Blueprint $t) {
                $t->id();
                $t->string('clave', 50)->unique();
                $t->string('nombre', 150);

                // Lo que el código lee para saber si escribe en el historial.
                // La clave no sirve: la escuela puede agregar «aprobada
                // parcialmente» y tiene que poder decidir si asienta.
                $t->boolean('asienta')->default(false);

                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->auditoria();
            });
        }

        foreach (self::DICTAMENES as [$clave, $nombre, $asienta, $orden]) {
            DB::table('dictamenes_revalidacion')->updateOrInsert(['clave' => $clave], [
                'nombre' => $nombre, 'asienta' => $asienta, 'orden' => $orden,
                'activo' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('revalidaciones')) {
            return;
        }

        Schema::create('revalidaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('estancia_id')->constrained('estancias')->cascadeOnDelete();

            // Lo que dijo el destino, tal cual. Ver el docblock.
            $t->string('materia_externa', 200);
            $t->string('calificacion_externa', 20)->nullable();

            $t->foreignId('plan_materia_id')->constrained('plan_materias');

            /*
             * La nota convertida a NUESTRA escala. Se captura: no hay tabla de
             * conversión universal entre sistemas de calificación y fabricar
             * una sería inventarle una nota a alguien.
             */
            $t->decimal('calificacion_equivalente', 5, 2);

            $t->foreignId('dictamen_id')->constrained('dictamenes_revalidacion');

            /*
             * En qué ciclo se asienta. Se elige al dictaminar y no se adivina:
             * `historial.ciclo_id` es NOT NULL y meter «el ciclo actual» pondría
             * la materia en un semestre en el que la persona no estuvo aquí.
             */
            $t->foreignId('ciclo_id')->constrained('ciclos');

            // El renglón que escribió, para poder deshacerlo. Null = todavía no
            // se asentó.
            $t->foreignId('historial_id')->nullable()->constrained('historial')->nullOnDelete();

            $t->timestamp('dictaminada_en')->nullable();
            $t->text('notas')->nullable();
            $t->auditoria();

            // Una por materia y estancia: dos serían dos asientos de la misma
            // materia y el alumno acabaría con créditos de más.
            $t->unique(['estancia_id', 'plan_materia_id'], 'revalidacion_unica_por_materia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revalidaciones');
        Schema::dropIfExists('dictamenes_revalidacion');
    }
};
