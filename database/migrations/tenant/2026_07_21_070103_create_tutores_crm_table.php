<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tutores_crm (TENANT) — rol materializado del CRM: acompaña al aspirante en
 * el proceso de admisión. NO es el tutor académico (Módulo 5) ni el tutor
 * familiar (Módulo 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutores_crm', function (Blueprint $table) {
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('clave_tutor', 50)->nullable();
            $table->foreignId('situacion_id')->constrained('situaciones_tutor');
            $table->auditoria();

            $table->primary('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutores_crm');
    }
};
