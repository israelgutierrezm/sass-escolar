<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * documento_ambitos (TENANT) — a quién se le pide cada documento.
 *
 * `documentos_requeridos` no distinguía destinatario: era el catálogo del
 * aspirante y punto, aunque al docente se le pide su acta igual que al alumno.
 *
 * Se resuelve con un pivote y NO con una columna `ambito` porque un mismo tipo
 * se le pide a varios: "Acta de nacimiento" es una sola cosa, y con una columna
 * habría que darla de alta tres veces —con tres nombres que acabarían
 * divergiendo— para pedírsela a aspirantes, alumnos y docentes.
 *
 * `ambito` va como varchar con constantes en el modelo, no como catálogo
 * TENANT-CONFIG: sus valores son los roles que el sistema conoce, no algo que
 * una escuela deba poder inventar.
 *
 * Sin filas, el documento no se le pide a nadie: queda en el catálogo pero
 * inactivo, que es lo que se quiere al dar de baja un requisito sin perder el
 * histórico de quienes ya lo entregaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_ambitos', function (Blueprint $table) {
            $table->foreignId('documento_id')->constrained('documentos_requeridos')->cascadeOnDelete();
            $table->string('ambito', 20); // aspirante / alumno / docente / tutor
            $table->auditoria();

            $table->primary(['documento_id', 'ambito']);
        });

        // Los documentos que ya existían son los del aspirante: es el único
        // expediente que había cuando se sembró el catálogo.
        DB::statement(
            "INSERT INTO documento_ambitos (documento_id, ambito, created_at, updated_at)
             SELECT id, 'aspirante', NOW(), NOW() FROM documentos_requeridos WHERE deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_ambitos');
    }
};
