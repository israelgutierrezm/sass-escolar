<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepara `usuarios` para el CENSO de cuentas: toda persona con un rol
 * —docente, alumno, aspirante, tutor, padre— tiene su cuenta, aparezca o no
 * con acceso configurado todavía.
 *
 *  - `email` pasa a NULLABLE: muchas personas (alumnos, aspirantes capturados a
 *    mano) no traen correo. El correo será el identificador de acceso cuando se
 *    habilite el ingreso (etapa 2); mientras, una cuenta puede existir sin él.
 *  - `acceso_configurado`: distingue las cuentas con contraseña usable (el
 *    personal que ya entra) de las de censo, creadas con una contraseña
 *    inservible a la espera del mecanismo de activación. Las existentes se
 *    marcan `true`; las de censo nacen en `false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->change();
            $table->boolean('acceso_configurado')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('acceso_configurado');
            $table->string('email', 150)->nullable(false)->change();
        });
    }
};
