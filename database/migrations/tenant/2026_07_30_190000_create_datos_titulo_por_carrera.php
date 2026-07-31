<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos del título que capturan los administradores POR CARRERA de cada alumno
 * (una fila por `matricula_oferta`). Son tres formularios de captura que
 * alimentan el XML del título electrónico y viven como pestañas del expediente
 * de la carrera. No son los formularios dinámicos de admisiones ("solicitud"):
 * estos son de esquema fijo, atado al estándar SEP.
 *
 *  - titulo_modalidad          → nodo Expedicion (+ fechaTerminacion de Carrera)
 *  - titulo_servicio_social    → nodo Expedicion (atributos de servicio social)
 *  - titulo_antecedente        → nodo Antecedente
 *
 * `entidad_federativa_id` referencia el catálogo LANDLORD (como
 * `personas.entidad_nacimiento_id`): se guarda el id sin llave foránea cruzada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titulo_modalidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->unique()->constrained('matricula_oferta')->cascadeOnDelete();
            $table->foreignId('modalidad_titulacion_id')->nullable()->constrained('modalidades_titulacion')->nullOnDelete();
            $table->date('fecha_expedicion')->nullable();
            $table->date('fecha_examen_profesional')->nullable();
            $table->date('fecha_exencion_examen')->nullable();
            // fechaTerminacion del nodo Carrera (cuándo el alumno terminó la carrera).
            $table->date('fecha_terminacion_carrera')->nullable();
            $table->unsignedBigInteger('entidad_federativa_id')->nullable(); // de expedición
            $table->auditoria();
        });

        Schema::create('titulo_servicio_social', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->unique()->constrained('matricula_oferta')->cascadeOnDelete();
            $table->boolean('cumplio_servicio_social')->nullable();
            $table->foreignId('fundamento_legal_ss_id')->nullable()
                ->constrained('fundamentos_legales_servicio_social')->nullOnDelete();
            $table->auditoria();
        });

        Schema::create('titulo_antecedente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_oferta_id')->unique()->constrained('matricula_oferta')->cascadeOnDelete();
            $table->string('institucion_procedencia', 255)->nullable();
            // Tipo de estudio antecedente: se reutiliza niveles_estudio y su
            // identificador_titulo (idTipoEstudioAntecedente).
            $table->foreignId('nivel_antecedente_id')->nullable()->constrained('niveles_estudio')->nullOnDelete();
            $table->unsignedBigInteger('entidad_federativa_id')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_terminacion')->nullable();
            $table->string('no_cedula', 60)->nullable();
            $table->auditoria();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titulo_antecedente');
        Schema::dropIfExists('titulo_servicio_social');
        Schema::dropIfExists('titulo_modalidad');
    }
};
