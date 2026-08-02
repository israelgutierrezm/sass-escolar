<?php

declare(strict_types=1);

use App\Support\IndiceQueSostieneUnaFk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retroalimentación por resultado, y fuera lo que no se usaba.
 *
 * ── Una retroalimentación no sirve para las dos respuestas ─────────────────
 * Al que acertó se le confirma por qué; al que falló se le explica dónde se
 * perdió. Con un solo texto había que escribir algo que valiera para ambos, y lo
 * que vale para ambos casi nunca dice nada: «recuerda revisar el tema 3» no le
 * sirve a quien ya lo entendió, y «correcto» no le sirve a quien no.
 *
 * `retroalimentacion` pasa a ser la del acierto: es lo que ya estaba escrito y
 * casi siempre se redactó pensando en quien contesta bien. Renombrarla conserva
 * lo capturado; borrarla y crear dos habría tirado el trabajo hecho.
 *
 * ── Se retiran `tema` y `dificultad` ───────────────────────────────────────
 * Decisión del usuario. Eran campos que había que llenar en cada reactivo y que
 * no alimentaban ninguna decisión del sistema: ni el sorteo, ni la
 * calificación, ni un reporte. Un campo que solo se captura es trabajo que se
 * cobra a quien redacta el examen y no le devuelve nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * El índice `(curso_id, tema)` apoyaba la búsqueda por tema, pero además
         * —por empezar con `curso_id`— sostenía la llave foránea al curso, y
         * MySQL se niega a dejarla sin ninguno. El helper crea el sustituto
         * ANTES de tirar el viejo, que es lo único que importa aquí.
         */
        IndiceQueSostieneUnaFk::reemplazar('reactivos', ['curso_id', 'tema'], 'curso_id');

        Schema::table('reactivos', function (Blueprint $table) {
            $table->renameColumn('retroalimentacion', 'retro_correcta');
        });

        Schema::table('reactivos', function (Blueprint $table) {
            $table->text('retro_incorrecta')->nullable()->after('retro_correcta');

            $table->dropColumn(['tema', 'dificultad']);
        });

        Schema::table('examenes', function (Blueprint $table) {
            /*
             * Cómo se presenta: todas las preguntas en una página, o una a la
             * vez con navegación. Lo decide quien arma el examen porque depende
             * del examen: veinte preguntas de opción múltiple se contestan de
             * corrido, y cinco casos largos se leen mejor de uno en uno.
             */
            $table->boolean('una_por_pagina')->default(false)->after('barajar_opciones');
        });
    }

    public function down(): void
    {
        Schema::table('examenes', function (Blueprint $table) {
            $table->dropColumn('una_por_pagina');
        });

        Schema::table('reactivos', function (Blueprint $table) {
            $table->string('tema')->nullable();
            $table->string('dificultad', 10)->nullable();

            $table->dropColumn('retro_incorrecta');
        });

        Schema::table('reactivos', function (Blueprint $table) {
            $table->renameColumn('retro_correcta', 'retroalimentacion');
        });

        IndiceQueSostieneUnaFk::reponer('reactivos', ['curso_id', 'tema'], 'curso_id');
    }
};
