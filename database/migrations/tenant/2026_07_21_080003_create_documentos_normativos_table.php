<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documentos_normativos (TENANT-CONFIG) — reglamento, lineamientos, aviso de
 * privacidad y demás documentos que una persona debe aceptar.
 *
 * Versionado con el mismo patrón que `formularios` (unique clave+version): al
 * cambiar el texto se sube la versión en vez de mutar la existente, para que
 * las aceptaciones ya otorgadas sigan apuntando al texto que se aceptó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_normativos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50); // reglamento_general, aviso_privacidad...
            $table->string('titulo', 200);
            $table->integer('version')->default(1);
            $table->text('contenido')->nullable(); // texto embebido, si no es archivo
            $table->string('ruta', 500)->nullable(); // PDF en S3, si aplica
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->boolean('obligatorio')->default(true);
            $table->auditoria();

            $table->unique(['clave', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_normativos');
    }
};
