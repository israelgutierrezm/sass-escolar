<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** tipos_evaluacion (TENANT-CONFIG) — ordinaria, extraordinaria, revalidación, recursamiento, a título, regularización. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_evaluacion');
    }
};
