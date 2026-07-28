<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disposición del menú lateral POR ROL: el orden y el anidamiento (hasta 3
 * niveles) que la escuela decidió para cada rol, arrastrando en el editor.
 *
 * Se guarda solo la ESTRUCTURA (un árbol de claves del catálogo), no las
 * etiquetas ni los permisos —esos viven en el catálogo del frontend—. Un rol sin
 * fila usa el orden por defecto. Reordenar aquí NO otorga acceso: la barra
 * lateral sigue filtrando por ámbito y permiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus_rol', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_id')->unique()->constrained('roles')->cascadeOnDelete();
            $table->json('estructura'); // [{clave, hijos: [{clave, hijos: [...]}]}]
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus_rol');
    }
};
