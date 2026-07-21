<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende la tabla `roles` de spatie/laravel-permission para que sea TAMBIÉN
 * el catálogo de roles de dominio de la spec (Módulo 1). Un solo catálogo: el
 * rol que una persona tiene es el mismo que carga los permisos, tal como
 * implica la spec al decir que los permisos se acotan al `rol_activo_id`.
 *
 * Mapeo de columnas:
 *  - `name` (de Spatie) guarda la CLAVE del rol (alumno, encargado_admisiones...),
 *    porque es la que usan hasRole(), assignRole() y el middleware `role:`.
 *  - `nombre` es la etiqueta para mostrar.
 *  - `tiempo_sesion` son los minutos de expiración de sesión del rol (legacy).
 *  - `rol_padre_id` da la JERARQUÍA: los roles funcionales cuelgan de una
 *    faceta. Ej.: encargado_admisiones y auxiliar_admisiones cuelgan de
 *    administrativo. Un rol sin padre es una faceta (lo que la persona ES).
 *    Los hijos heredan los permisos de sus ancestros (ver App\Models\Identidad\Rol).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('nombre', 120)->after('name');
            $table->unsignedSmallInteger('tiempo_sesion')->nullable()->after('nombre');
            $table->foreignId('rol_padre_id')->nullable()->after('tiempo_sesion')
                ->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['rol_padre_id']);
            $table->dropColumn(['nombre', 'tiempo_sesion', 'rol_padre_id']);
        });
    }
};
