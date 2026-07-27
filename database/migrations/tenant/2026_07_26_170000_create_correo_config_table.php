<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de correo (SMTP) de la escuela, por tenant.
 *
 * Pensada para una cuenta de Gmail: host/puerto/cifrado ya vienen con los
 * valores de Gmail. La contraseña se guarda CIFRADA (cast `encrypted`) y nunca
 * se devuelve completa al frontend ni a logs —Gmail exige una «contraseña de
 * aplicación», no la del correo—.
 *
 * Config única por escuela (una fila, vía `CorreoConfig::actual()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correo_config', function (Blueprint $table) {
            $table->id();
            $table->boolean('activo')->default(false);
            $table->string('host', 120)->default('smtp.gmail.com');
            $table->unsignedSmallInteger('puerto')->default(587);
            $table->string('cifrado', 4)->default('tls'); // tls | ssl
            $table->string('usuario', 190)->nullable();    // correo de Gmail
            $table->text('password')->nullable();          // contraseña de aplicación (cifrada)
            $table->string('remitente_correo', 190)->nullable();
            $table->string('remitente_nombre', 120)->nullable();

            $table->string('prueba_estado', 12)->nullable(); // ok | error
            $table->text('prueba_mensaje')->nullable();
            $table->timestamp('prueba_en')->nullable();

            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correo_config');
    }
};
