<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * instituciones (TENANT) — la persona moral educativa dueña de los campus.
 *
 * Es un dato de ENCABEZADO: nombre y logo con los que se membreta lo que la
 * escuela emite. No condiciona lógica —un campus apunta a su institución solo
 * de forma informativa—, por eso vive como catálogo simple y no como una
 * entidad con reglas.
 *
 * Se siembra una institución por defecto con el nombre del tenant para que los
 * campus que ya existen tengan a qué apuntar y para que la preselección
 * automática «si solo hay una» tenga algo que preseleccionar desde el arranque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instituciones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('clave', 50);
            $tabla->string('nombre');
            // La ruta del archivo en el disco privado, no el binario. Se sirve
            // por una ruta autenticada, igual que la foto de una persona.
            $tabla->string('logo_url', 500)->nullable();
            $tabla->auditoria();

            $tabla->unique(['clave', 'deleted_at']);
        });

        // Una institución inicial para no dejar la tabla vacía: su nombre es el
        // del tenant, que es el mejor default disponible sin preguntar.
        DB::table('instituciones')->insert([
            'clave' => 'PRINCIPAL',
            'nombre' => (string) (tenant('id') ?? 'Institución'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('instituciones');
    }
};
