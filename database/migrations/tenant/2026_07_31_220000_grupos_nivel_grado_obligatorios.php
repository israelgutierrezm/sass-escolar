<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El grupo pasa a declarar SU nivel y SU grado.
 *
 * Antes el nivel no se guardaba: se deducía del plan de estudios, y como el
 * plan es opcional (hay grupos "sin plan fijo", que toman materias de varios),
 * había grupos sin nivel alguno. Para el LMS eso no sirve: un grupo es "1° A de
 * Secundaria" antes que cualquier otra cosa, y el nivel es intrínseco.
 *
 * `semestre` es el GRADO del grupo y ahora es obligatorio. Sigue siendo un dato
 * del grupo, no de sus materias: abrirle una materia de otro grado no se lo
 * cambia —eso solo se hace editando el grupo—, porque el grado dice quiénes lo
 * cursan, no qué se imparte.
 *
 * La `situacion_id` se conserva en la tabla (un grupo sí se cierra o se cancela
 * después) pero deja de capturarse al crear: nace abierto siempre. Preguntarlo
 * en el alta era ofrecer un estado que no puede tener todavía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            // Landlord, sin FK: mismo criterio que `carreras.nivel_estudios_id`.
            $table->unsignedBigInteger('nivel_estudios_id')->nullable()->after('campus_id');
        });

        // Los grupos que ya existen heredan el nivel de su plan; los que no
        // tienen plan toman el nivel del ciclo si el ciclo está acotado a uno.
        DB::statement('
            UPDATE grupos g
            JOIN planes_estudio p ON p.id = g.plan_id
            JOIN carreras c ON c.id = p.carrera_id
            SET g.nivel_estudios_id = c.nivel_estudios_id
            WHERE g.nivel_estudios_id IS NULL
        ');

        if (Schema::hasTable('ciclo_nivel')) {
            DB::statement('
                UPDATE grupos g
                JOIN (
                    SELECT ciclo_id, MIN(nivel_estudios_id) AS nivel
                    FROM ciclo_nivel GROUP BY ciclo_id HAVING COUNT(*) = 1
                ) n ON n.ciclo_id = g.ciclo_id
                SET g.nivel_estudios_id = n.nivel
                WHERE g.nivel_estudios_id IS NULL
            ');
        }

        // El grado y el cupo pasan a ser obligatorios. A lo que quedó sin dato
        // se le pone un valor de arranque explícito en vez de dejarlo nulo: es
        // preferible un 1 visible y corregible a un hueco que rompe el alta.
        DB::table('grupos')->whereNull('semestre')->update(['semestre' => 1]);
        DB::table('grupos')->whereNull('cupo')->update(['cupo' => 30]);

        Schema::table('grupos', function (Blueprint $table) {
            $table->unsignedBigInteger('nivel_estudios_id')->nullable(false)->change();
            $table->integer('semestre')->nullable(false)->change();
            $table->integer('cupo')->nullable(false)->change();

            $table->index('nivel_estudios_id');
        });
    }

    public function down(): void
    {
        Schema::table('grupos', function (Blueprint $table) {
            $table->dropIndex(['nivel_estudios_id']);
            $table->dropColumn('nivel_estudios_id');
            $table->integer('semestre')->nullable()->change();
            $table->integer('cupo')->nullable()->change();
        });
    }
};
