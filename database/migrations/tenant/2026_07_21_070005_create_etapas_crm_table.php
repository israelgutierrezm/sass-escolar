<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * etapas_crm (TENANT-CONFIG) — embudo configurable, si se quiere un pipeline
 * visual además del `paso` numérico del aspirante. Lleva `orden` porque es un
 * embudo: la secuencia importa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_crm', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapas_crm');
    }
};
