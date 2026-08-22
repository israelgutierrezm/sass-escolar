<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El parentesco deja de estar cableado, y el vínculo dice dos cosas más.
 *
 * ── Por qué el parentesco pasa a ser tabla ─────────────────────────────────
 * Era una cadena libre con la lista escrita a mano en el controlador
 * (`Rule::in(['padre','madre','tutor','otro'])`) y otra lista de etiquetas en
 * el Vue. Dos copias del mismo enumerable en dos lenguajes distintos, y ninguna
 * escuela podía agregar «abuela» o «hermano mayor» sin tocar código — que es
 * exactamente lo que la regla del cliente prohíbe: si algo enumerable se cablea,
 * hay que poder explicar por qué no es tabla, y aquí no había explicación.
 *
 * ── Dos hechos del vínculo que la escuela necesita y no tenía dónde poner ──
 * `es_contacto_emergencia` contesta a quién se le llama, y `es_responsable_pago`
 * a quién se le cobra. No son permisos de visibilidad: son datos de la relación,
 * y hoy se resolvían preguntando por teléfono.
 *
 * ── Y se retira `acceso_materia` ──────────────────────────────────────────
 * Estaba declarada en el modelo y en el pivote y NO la leía nadie: ni un
 * controlador, ni una vista, ni un servicio. Una columna que promete «acceso a
 * la materia» y no hace nada es peor que no tenerla, porque el día que alguien
 * la vea supondrá que algo la respeta. Además la spec es explícita en que el
 * LMS no se expone a familiares: esa exclusión tiene que ser ESTRUCTURAL —no
 * existe ruta que lo permita— y no una casilla que alguien pueda palomear.
 *
 * Lo que NO se agrega, a propósito: las banderas finas de la spec
 * (`ve_pagos`, `ve_facturas`, `ve_asistencia`, `ve_avisos`). Ninguna tendría hoy
 * quien la lea —el portal muestra historial y finanzas, y los avisos llegan por
 * rol—, y este proyecto ya tuvo que retirar ajustes y permisos que nadie
 * consultaba. Se agregan cuando exista la pantalla que las respete.
 */
return new class extends Migration
{
    /** Lo mínimo que toda escuela necesita; el resto lo agrega ella. */
    private const SEMILLA = [
        ['padre', 'Padre', 10],
        ['madre', 'Madre', 20],
        ['tutor', 'Tutor legal', 30],
        ['otro', 'Otro', 90],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('parentescos')) {
            Schema::create('parentescos', function (Blueprint $table) {
                $table->id();
                $table->string('clave', 50)->unique();
                $table->string('nombre', 100);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->auditoria();
            });
        }

        foreach (self::SEMILLA as [$clave, $nombre, $orden]) {
            DB::table('parentescos')->updateOrInsert(
                ['clave' => $clave],
                ['nombre' => $nombre, 'orden' => $orden, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        if (! Schema::hasColumn('tutores_alumno', 'parentesco_id')) {
            Schema::table('tutores_alumno', function (Blueprint $table) {
                // Nullable: hay vínculos viejos con el texto en blanco, y
                // obligar aquí haría fallar la migración sobre datos reales.
                $table->foreignId('parentesco_id')->nullable()->after('alumno_persona_id')
                    ->constrained('parentescos')->nullOnDelete();
            });
        }

        // El texto que había se traduce por CLAVE, que es justo lo que la lista
        // cableada guardaba: 'padre', 'madre', 'tutor', 'otro'.
        if (Schema::hasColumn('tutores_alumno', 'parentesco')) {
            DB::statement('
                UPDATE tutores_alumno t
                JOIN parentescos p ON p.clave = t.parentesco
                SET t.parentesco_id = p.id
                WHERE t.parentesco_id IS NULL
            ');

            Schema::table('tutores_alumno', fn (Blueprint $table) => $table->dropColumn('parentesco'));
        }

        Schema::table('tutores_alumno', function (Blueprint $table) {
            if (! Schema::hasColumn('tutores_alumno', 'es_contacto_emergencia')) {
                $table->boolean('es_contacto_emergencia')->default(false)->after('parentesco_id');
            }

            if (! Schema::hasColumn('tutores_alumno', 'es_responsable_pago')) {
                $table->boolean('es_responsable_pago')->default(false)->after('es_contacto_emergencia');
            }
        });

        if (Schema::hasColumn('tutores_alumno', 'acceso_materia')) {
            Schema::table('tutores_alumno', fn (Blueprint $table) => $table->dropColumn('acceso_materia'));
        }
    }

    public function down(): void
    {
        Schema::table('tutores_alumno', function (Blueprint $table) {
            if (! Schema::hasColumn('tutores_alumno', 'parentesco')) {
                $table->string('parentesco', 50)->nullable()->after('alumno_persona_id');
            }

            if (! Schema::hasColumn('tutores_alumno', 'acceso_materia')) {
                $table->boolean('acceso_materia')->default(false);
            }
        });

        DB::statement('
            UPDATE tutores_alumno t
            JOIN parentescos p ON p.id = t.parentesco_id
            SET t.parentesco = p.clave
        ');

        Schema::table('tutores_alumno', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parentesco_id');
            $table->dropColumn(['es_contacto_emergencia', 'es_responsable_pago']);
        });

        Schema::dropIfExists('parentescos');
    }
};
