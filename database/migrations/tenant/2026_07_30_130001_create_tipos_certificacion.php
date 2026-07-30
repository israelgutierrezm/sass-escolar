<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tipos_certificacion (TENANT) — catálogo del tipo de Documento Electrónico de
 * Certificación de la SEP: 79 = Total, 80 = Parcial. Se administra desde
 * Certificación → Configuración → Catálogos. Los dos oficiales van `protegido`
 * para que no se borren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_certificacion', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50);
            $table->string('identificador', 40)->nullable();
            $table->string('nombre', 255);
            $table->boolean('protegido')->default(false);
            $table->auditoria();
        });

        DB::table('tipos_certificacion')->insert([
            ['clave' => '79', 'identificador' => '79', 'nombre' => 'Total', 'protegido' => true, 'created_at' => now(), 'updated_at' => now()],
            ['clave' => '80', 'identificador' => '80', 'nombre' => 'Parcial', 'protegido' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_certificacion');
    }
};
