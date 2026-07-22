<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ventanas_captura (TENANT) — hasta cuándo puede capturar el docente, por parcial.
 *
 * `ciclos.captura_calif_hasta` es UNA sola fecha para todo el ciclo, y no
 * bloquea nada: solo marca el acta como extemporánea al asentarla. Eso no sirve
 * para una escuela que corta la captura del primer parcial en octubre y la del
 * segundo en diciembre.
 *
 * Estas ventanas SÍ bloquean, y lo hacen por parcial. `parcial` en NULL cubre
 * los rubros que van directo al curso (los que en `esquema_evaluacion` no
 * cuelgan de ningún corte).
 *
 * **Sin ventanas definidas, el ciclo captura libre.** Es deliberado: la escuela
 * que no quiere gestionar calendario de captura no tiene que configurar nada, y
 * los ciclos que ya existían siguen funcionando igual que antes.
 *
 * `activa` permite apagar una ventana sin borrarla, que es como se opera en la
 * práctica ("ábrele otra vez el primer parcial una semana").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventanas_captura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ciclo_id')->constrained('ciclos')->cascadeOnDelete();
            $table->smallInteger('parcial')->nullable(); // NULL = rubros sin parcial
            $table->string('nombre', 80)->nullable();    // "Primer parcial"
            $table->date('desde');
            $table->date('hasta');
            $table->boolean('activa')->default(true);
            $table->auditoria();

            // Una sola ventana por corte. MySQL admite varias filas con parcial
            // NULL en un índice único, así que ese caso se valida en la app.
            $table->unique(['ciclo_id', 'parcial']);
        });

        Schema::create('excepciones_captura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ventana_id')->constrained('ventanas_captura')->cascadeOnDelete();
            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo')->cascadeOnDelete();

            // A quién se le abre. NULL = a cualquier docente de esa materia,
            // que es el caso común cuando el titular cambió a media captura.
            $table->foreignId('persona_id')->nullable()->constrained('personas');

            $table->date('hasta');
            $table->string('motivo', 255);

            // Quién la concedió. Una excepción es una decisión administrativa y
            // tiene que poder responderse "¿quién le abrió esto y por qué?".
            $table->foreignId('autorizada_por')->nullable()->constrained('personas');

            $table->auditoria();

            $table->index(['asignatura_grupo_id', 'ventana_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excepciones_captura');
        Schema::dropIfExists('ventanas_captura');
    }
};
