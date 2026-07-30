<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de certificación (TENANT) — el vehículo para certificar alumnos en
 * bloque. Un responsable de certificación crea el lote, le agrega alumnos que
 * ya cerraron su plan y aún no tienen certificado emitido, lo cierra («en
 * espera de firma») y lo firma: cada alumno del lote recibe su XML sellado.
 *
 * El lote NO discrimina campus ni carrera: mientras quien lo arma tenga alcance
 * sobre el campus del alumno, puede juntar alumnos de varios campus y planes.
 * Los estados viven como string (máquina de estados en
 * App\Enums\EstadoLoteCertificacion), no como catálogo, porque son fijos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_certificacion', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 40)->unique();
            $table->string('nombre', 160)->nullable();
            $table->string('estado', 30)->default('borrador');

            // Quién firmó y con qué certificado (serie). Nulos hasta la firma; se
            // conservan aunque el responsable se desactive después.
            $table->foreignId('responsable_id')->nullable()->constrained('responsables')->nullOnDelete();
            $table->foreignId('certificado_responsable_id')->nullable()
                ->constrained('certificados_responsable')->nullOnDelete();

            $table->timestamp('cerrado_en')->nullable();
            $table->timestamp('firmado_en')->nullable();

            $table->auditoria();

            $table->index('estado');
        });

        Schema::create('certificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('lotes_certificacion')->cascadeOnDelete();
            $table->foreignId('matricula_oferta_id')->constrained('matricula_oferta')->restrictOnDelete();

            // pendiente (en el lote, sin firmar) | certificado | error.
            $table->string('estado', 20)->default('pendiente');

            $table->string('folio', 60)->nullable();
            // Serie del certificado del responsable con que se selló.
            $table->string('no_certificado', 100)->nullable();
            $table->longText('cadena_original')->nullable();
            $table->longText('sello')->nullable();
            // Ruta del XML firmado en el disco privado del tenant.
            $table->string('xml_path', 255)->nullable();
            // Foto de los datos académicos al momento de certificar (alumno,
            // plan, materias, promedio): el certificado no debe cambiar si
            // después se toca el kárdex.
            $table->json('datos_json')->nullable();
            $table->timestamp('fecha_certificacion')->nullable();
            $table->string('error_mensaje', 255)->nullable();

            $table->auditoria();

            // Un alumno-carrera no puede estar dos veces en el mismo lote. Que no
            // esté certificado en OTRO lote lo cuida el controlador.
            $table->unique(['lote_id', 'matricula_oferta_id']);
            $table->index('matricula_oferta_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificaciones');
        Schema::dropIfExists('lotes_certificacion');
    }
};
