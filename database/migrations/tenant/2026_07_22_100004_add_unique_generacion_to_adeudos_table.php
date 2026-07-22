<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotencia de la generación de adeudos, impuesta por la base.
 *
 * `GeneradorAdeudos` ya comprueba antes de insertar, pero va a correr como job
 * programado y dos ejecuciones que se traslapen —un reintento de la cola, un
 * administrador que aprieta el botón mientras el cron corre— pasarían las dos
 * por el SELECT antes de que ninguna inserte. Un índice único es lo único que
 * de verdad impide cobrarle dos veces la colegiatura de marzo a un alumno.
 *
 * La terna es (matrícula, regla, periodo): la misma regla no genera dos veces
 * el mismo periodo para la misma matrícula. Los adeudos capturados a mano
 * llevan `regla_id` en NULL y MySQL trata los NULL como distintos, así que
 * quedan fuera del índice —que es lo correcto: una reposición de credencial
 * cobrada dos veces son dos cargos legítimos—.
 *
 * Ojo con la trampa de siempre: el soft delete NO libera un índice único. Un
 * adeudo borrado lógicamente sigue ocupando su terna, así que la comprobación
 * previa del generador consulta con `withTrashed()`. Es además el
 * comportamiento que se quiere: si alguien canceló la colegiatura de marzo, la
 * siguiente corrida no debe resucitarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adeudos', function (Blueprint $table) {
            $table->unique(
                ['matricula_oferta_id', 'regla_id', 'periodo_etiqueta'],
                'adeudos_generacion_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('adeudos', function (Blueprint $table) {
            $table->dropUnique('adeudos_generacion_unique');
        });
    }
};
