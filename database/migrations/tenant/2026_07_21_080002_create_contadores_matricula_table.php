<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contadores_matricula (TENANT) — consecutivo por llave de ámbito.
 *
 * Existe para que la numeración sea ATÓMICA: dos administradores convirtiendo
 * aspirantes al mismo tiempo NO deben obtener la misma matrícula. Por eso el
 * consecutivo se toma de aquí con un incremento atómico y jamás de un
 * MAX(matricula)+1, que sí colisiona bajo concurrencia.
 *
 * EXCEPCIÓN a la convención de auditoría (la segunda del proyecto, junto con
 * la bitácora `auditoria`): esta tabla NO lleva soft delete ni created_by/
 * updated_by. Un `deleted_at` aquí sería un arma: al ocultar un contador se
 * reiniciaría la numeración y se emitirían matrículas duplicadas. Se conservan
 * created_at/updated_at para observabilidad.
 *
 * SIN `id` AUTO_INCREMENT a propósito: la PK es la propia `clave`. Un id
 * autoincremental rompería el incremento atómico del generador, porque un
 * INSERT exitoso sobreescribe LAST_INSERT_ID() con el id de la fila nueva y
 * el consecutivo entregado dejaría de ser 1 — provocando matrículas
 * duplicadas cuando la secuencia normal alcanzara ese número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contadores_matricula', function (Blueprint $table) {
            $table->string('clave', 150)->primary(); // p.ej. "plan:5|anio:2026"
            $table->unsignedBigInteger('valor')->default(0); // último consecutivo entregado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contadores_matricula');
    }
};
