<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca qué roles NO se pueden renombrar ni borrar desde la pantalla.
 *
 * Al abrir la administración de roles, la escuela puede crear los suyos y
 * cambiarles los permisos — que es justo lo que se busca. Pero hay claves de
 * las que depende el CÓDIGO: `CapturaCalificacionesController` acota al docente
 * comprobando que su rol activo sea la faceta `docente` o descienda de ella. Si
 * alguien la renombra desde la interfaz, ese filtro deja de aplicar en silencio
 * y cualquier docente podría calificar al grupo de otro.
 *
 * `protegido` no congela el rol: su NOMBRE visible, su tiempo de sesión y sus
 * PERMISOS siguen siendo editables. Lo único que fija es la clave y su
 * existencia. Es la diferencia entre "no lo puedes configurar" y "no le puedes
 * quitar el piso al código".
 *
 * Se marcan las facetas base sembradas por `RolSeeder`: son las que el sistema
 * conoce por nombre. Los roles funcionales (encargado de admisiones, auxiliar
 * de finanzas...) NO se protegen: son ejemplos útiles, y una escuela debe poder
 * borrarlos si su organigrama es otro.
 */
return new class extends Migration
{
    /** Las facetas que el código conoce por su clave. */
    private const FACETAS_DEL_SISTEMA = [
        'administrativo',
        'docente',
        'alumno',
        'aspirante',
        'tutor_educativo',
        'padre_familia',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('protegido')->default(false)->after('rol_padre_id');
        });

        DB::table('roles')
            ->whereIn('name', self::FACETAS_DEL_SISTEMA)
            ->update(['protegido' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('protegido');
        });
    }
};
