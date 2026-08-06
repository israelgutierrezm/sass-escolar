<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un bloque de horario dice si es presencial o en línea.
 *
 * `horarios_asignatura_grupo` guardaba día, hora y aula. Con clases en línea el
 * aula queda en NULL, y hasta ahora eso era ambiguo: podía significar «en
 * línea» o «todavía no le asignan salón». Son dos cosas muy distintas cuando
 * alguien revisa qué falta por resolver.
 *
 * Además el generador lo necesita para no ocupar aulas con clases que no las
 * usan, que es de donde salen la mitad de los choques falsos.
 *
 * `presencial` por omisión: es lo que son todos los bloques que ya existan, y
 * suponer lo contrario cambiaría el significado de datos ya capturados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('horarios_asignatura_grupo', function (Blueprint $table) {
            $table->string('modalidad', 20)->default('presencial')->after('hora_fin');
        });
    }

    public function down(): void
    {
        Schema::table('horarios_asignatura_grupo', function (Blueprint $table) {
            $table->dropColumn('modalidad');
        });
    }
};
