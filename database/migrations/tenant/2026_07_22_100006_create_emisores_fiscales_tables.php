<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Varias razones sociales por escuela.
 *
 * Aclaración del cliente: una escuela puede facturar con más de una persona
 * moral —bachillerato con una, licenciatura con otra, posgrado con otra, y a
 * veces una carrera suelta con la suya—. Hasta ahora el emisor era uno solo por
 * instalación, en `config/cfdi.php`, y eso emitía todos los CFDI a nombre de la
 * misma razón social: comprobantes inválidos para la mitad de la escuela.
 *
 * La asignación va en tabla PIVOTE y no en una columna del emisor, por la misma
 * razón que `documento_ambitos`: una razón social factura VARIAS cosas a la vez
 * —todo bachillerato Y además la maestría en derecho—. Con una columna habría
 * que dar de alta la misma persona moral tres veces, con tres RFC iguales y
 * tres juegos de certificados que acabarían divergiendo.
 *
 * Gana la asignación MÁS ESPECÍFICA: carrera → nivel de estudios → global. Es
 * el mismo criterio de `planes_cobro` y de `reglas_matricula`, y es lo que
 * permite decir "todo se factura con la A, salvo posgrado, que va con la B"
 * sin repetir la A en cada carrera.
 *
 * `aplica_a_id` va sin FK porque apunta a dos cosas distintas: `carreras` (del
 * tenant) o `niveles_estudio` (de la LANDLORD, que por decisión del proyecto
 * nunca lleva FK cruzada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emisores_fiscales', function (Blueprint $table) {
            $table->id();
            $table->string('rfc', 13)->unique();
            $table->string('razon_social', 255);
            $table->string('regimen_fiscal', 5);
            $table->string('cp', 5);

            // Cada persona moral timbra con SU propio certificado de sello
            // digital: son de ella, no de la instalación. Los archivos van al
            // disco privado y aquí solo su ruta; las contraseñas se guardan
            // cifradas (cast `encrypted` en el modelo), nunca en claro.
            $table->string('certificado_ruta', 255)->nullable();
            $table->string('llave_ruta', 255)->nullable();
            $table->text('llave_password')->nullable();
            $table->text('pac_usuario')->nullable();
            $table->text('pac_password')->nullable();

            $table->boolean('activo')->default(true);
            $table->auditoria();
        });

        Schema::create('emisor_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emisor_id')->constrained('emisores_fiscales')->cascadeOnDelete();
            $table->string('aplica_a_tipo', 20); // global / nivel / carrera
            $table->unsignedBigInteger('aplica_a_id')->nullable(); // NULL si global
            $table->auditoria();

            $table->index(['aplica_a_tipo', 'aplica_a_id']);
        });

        // El emisor se COPIA a la factura, igual que el receptor: si la escuela
        // cambia de régimen o corrige su razón social, el comprobante ya
        // timbrado debe seguir diciendo lo que se timbró. `emisor_id` queda
        // solo como referencia de dónde salió.
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('emisor_id')->nullable()->after('matricula_oferta_id')
                ->constrained('emisores_fiscales');
            $table->string('emisor_rfc', 13)->nullable()->after('emisor_id');
            $table->string('emisor_razon_social', 255)->nullable()->after('emisor_rfc');
            $table->string('emisor_regimen_fiscal', 5)->nullable()->after('emisor_razon_social');
            $table->string('emisor_cp', 5)->nullable()->after('emisor_regimen_fiscal');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropForeign(['emisor_id']);
            $table->dropColumn([
                'emisor_id', 'emisor_rfc', 'emisor_razon_social',
                'emisor_regimen_fiscal', 'emisor_cp',
            ]);
        });

        Schema::dropIfExists('emisor_asignaciones');
        Schema::dropIfExists('emisores_fiscales');
    }
};
