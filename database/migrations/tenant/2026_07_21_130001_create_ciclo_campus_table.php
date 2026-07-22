<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ciclo_campus (TENANT) — un ciclo aplica a 1..N campus.
 *
 * Corrige `ciclos.campus_id` (un solo campus por ciclo, o NULL = global). La
 * escuela con 5 campus normalmente abre el mismo ciclo en 2 o 3, no en todos ni
 * en uno solo: con la columna anterior había que duplicar el ciclo por campus,
 * y entonces "2026-2027/1" dejaba de ser UN periodo para volverse tres, con las
 * inscripciones repartidas entre ciclos que en realidad eran el mismo.
 *
 * Sin filas en este pivote el ciclo sigue siendo GLOBAL de la escuela: es la
 * misma semántica que tenía `campus_id` en NULL, ahora expresada por ausencia.
 *
 * La clave del ciclo pasa a ser única en toda la escuela (antes era única por
 * campus, porque el campus formaba parte de su identidad).
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL no tiene DDL transaccional: si esta migración falla a media
        // ejecución, lo ya aplicado se queda. Por eso cada paso comprueba su
        // propio estado y la migración se puede volver a correr sin limpiar a
        // mano. El orden importa: primero se crea y llena el pivote, y solo al
        // final se suelta la columna vieja.
        if (! Schema::hasTable('ciclo_campus')) {
            Schema::create('ciclo_campus', function (Blueprint $table) {
                $table->foreignId('ciclo_id')->constrained('ciclos')->cascadeOnDelete();
                $table->foreignId('campus_id')->constrained('campus')->cascadeOnDelete();
                $table->auditoria();

                $table->primary(['ciclo_id', 'campus_id']);
            });
        }

        if (! Schema::hasColumn('ciclos', 'campus_id')) {
            return; // ya migrado
        }

        // Los ciclos que ya existían conservan su campus: se copia al pivote
        // antes de soltar la columna. Los globales (campus_id NULL) no generan
        // fila, que es justo lo que significa "global" aquí.
        DB::statement(
            'INSERT IGNORE INTO ciclo_campus (ciclo_id, campus_id, created_at, updated_at)
             SELECT id, campus_id, NOW(), NOW() FROM ciclos WHERE campus_id IS NOT NULL'
        );

        // La FK de campus_id se apoya en el índice unique (campus_id, clave),
        // y MySQL no deja soltar un índice del que depende una constraint. Por
        // eso la llave foránea se suelta en su propia sentencia, antes.
        Schema::table('ciclos', function (Blueprint $table) {
            $table->dropForeign(['campus_id']);
        });

        Schema::table('ciclos', function (Blueprint $table) {
            $table->dropUnique(['campus_id', 'clave']);
            $table->dropColumn('campus_id');
            $table->unique('clave');
        });
    }

    public function down(): void
    {
        Schema::table('ciclos', function (Blueprint $table) {
            $table->dropUnique(['clave']);
            $table->foreignId('campus_id')->nullable()->after('id')->constrained('campus');
        });

        // Se recupera un campus por ciclo (el de menor id): la vuelta atrás no
        // puede representar los ciclos multi-campus, así que se queda con uno.
        DB::statement(
            'UPDATE ciclos c SET campus_id = (
                SELECT MIN(cc.campus_id) FROM ciclo_campus cc WHERE cc.ciclo_id = c.id
             )'
        );

        Schema::table('ciclos', function (Blueprint $table) {
            $table->unique(['campus_id', 'clave']);
        });

        Schema::dropIfExists('ciclo_campus');
    }
};
