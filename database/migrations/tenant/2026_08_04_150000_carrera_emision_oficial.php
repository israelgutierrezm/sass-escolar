<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué documentos oficiales emite cada carrera.
 *
 * No toda carrera termina en certificado y título electrónicos ante la SEP: hay
 * diplomados, cursos y programas de educación continua que conviven en el mismo
 * catálogo y no tienen RVOE que respalde una emisión. Sin declararlo, sus
 * alumnos aparecían como candidatos en los lotes y con pestaña de titulación en
 * su expediente, ofreciendo un trámite que no existe.
 *
 * Nacen en `true` porque es lo que hasta hoy se asumía de todas: apagarlo es una
 * decisión que la escuela toma carrera por carrera, no algo que una migración
 * pueda adivinar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->boolean('emite_certificado')->default(true)->after('imagen_url');
            $tabla->boolean('emite_titulo')->default(true)->after('emite_certificado');
        });
    }

    public function down(): void
    {
        Schema::table('carreras', function (Blueprint $tabla) {
            $tabla->dropColumn(['emite_certificado', 'emite_titulo']);
        });
    }
};
