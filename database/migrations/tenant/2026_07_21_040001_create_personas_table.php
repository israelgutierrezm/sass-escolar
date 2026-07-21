<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * personas (TENANT) — identidad única. Toda persona del sistema vive aquí una
 * sola vez; sus roles se activan/desactivan sin tocar estos datos.
 *
 * Las columnas *_id que apuntan a catálogos LANDLORD (sexos, generos, paises,
 * entidades_federativas) NO llevan FK real: viven en otra base de datos (la
 * central) y una FK cruzada hardcodearía su nombre y es frágil en multi-database.
 * La integridad se valida en la capa de aplicación; las relaciones Eloquent
 * resuelven cross-DB porque los modelos landlord usan CentralConnection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('curp', 18)->nullable()->unique();
            $table->string('rfc', 13)->nullable();
            $table->string('nombre');
            $table->string('primer_apellido');
            $table->string('segundo_apellido')->nullable();
            $table->date('fecha_nacimiento')->nullable();

            // Referencias lógicas a catálogos LANDLORD (otra BD): sin FK real.
            $table->unsignedBigInteger('sexo_id');
            $table->unsignedBigInteger('genero_id')->nullable();
            $table->unsignedBigInteger('pais_nacimiento_id')->nullable();
            $table->unsignedBigInteger('entidad_nacimiento_id')->nullable();

            $table->string('email', 150)->nullable();
            $table->string('correo_institucional', 150)->nullable();
            $table->string('celular', 20)->nullable();

            $table->auditoria();

            // Búsqueda de personas como en el legacy IMEP.
            $table->fullText(['nombre', 'primer_apellido', 'segundo_apellido', 'curp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
