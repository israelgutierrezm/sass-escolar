<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * disenos_historial (TENANT) — cómo se imprime el historial académico.
 *
 * ── Por qué NO se diseña con cajas como la credencial ──────────────────────
 * Porque no es lo mismo. Una credencial es una superficie fija donde cabe lo
 * que cabe, y ahí poner cada dato en su coordenada es exactamente lo correcto.
 * Un historial es un documento que CRECE: una alumna de primer semestre trae
 * siete renglones y una egresada trescientos, así que no hay coordenada que
 * valga para la fila número doscientos. Lo que la escuela decide aquí es qué
 * lleva el encabezado, QUÉ COLUMNAS trae la tabla y en qué orden, cómo se
 * agrupan las materias y qué se firma al pie.
 *
 * Se vio en los ejemplos reales: entre un historial de la UMSA, uno de la UNAM
 * y uno de un bachillerato, la maqueta cambia poco y las COLUMNAS cambian
 * mucho —unos imprimen créditos, otros la calificación con letra, otros el
 * folio del acta y el grupo—. Un editor de coordenadas no habría servido para
 * ninguno.
 *
 * ── Por nivel de estudios, y sólo por eso ──────────────────────────────────
 * A diferencia de la credencial, aquí no hay rol que elegir: el historial es
 * de los alumnos y de nadie más. Lo que sí varía es el nivel —un bachillerato
 * imprime semestres y una licenciatura créditos—, así que la variante se
 * resuelve igual que allá: la del nivel si existe, y si no la general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disenos_historial', function (Blueprint $table) {
            $table->id();

            /*
             * Nulo = el diseño general, el que usa todo nivel sin variante
             * propia. Sin FK: apunta al catálogo `niveles_estudio`, que es de la
             * base CENTRAL. Misma regla que `carreras.nivel_estudios_id`.
             */
            $table->unsignedBigInteger('nivel_estudios_id')->nullable();

            // ── Encabezado ──────────────────────────────────────────────
            $table->string('titulo')->default('Historial académico');
            $table->string('subtitulo')->nullable();
            $table->boolean('muestra_logo')->default(true);
            $table->boolean('muestra_nombre_escuela')->default(true);

            /*
             * Qué datos del alumno se imprimen arriba, y en qué orden.
             *
             * JSON y no columnas: son diez posibles y cada escuela elige tres o
             * seis. Con una columna booleana por dato, agregar «turno» mañana
             * sería una migración; así es una entrada en el catálogo.
             */
            $table->json('campos_alumno')->nullable();

            // ── La tabla de materias ────────────────────────────────────
            $table->json('columnas')->nullable();

            /*
             * Cómo se agrupan las materias: por periodo del plan, por ciclo
             * escolar, o sin agrupar.
             *
             * No es cosmético. Por PERIODO se lee el avance del plan —una
             * materia recursada cae junto a sus compañeras de semestre—; por
             * CICLO se lee la historia real de la persona. Las dos son
             * defendibles y por eso se elige.
             */
            $table->string('agrupacion')->default('periodo');

            // ── Resumen y pie ───────────────────────────────────────────
            $table->boolean('muestra_resumen')->default(true);
            $table->boolean('muestra_promedio')->default(true);
            $table->boolean('muestra_creditos')->default(true);
            $table->text('leyenda')->nullable();
            $table->string('responsable_nombre')->nullable();
            $table->string('responsable_cargo')->nullable();
            $table->string('firma_imagen')->nullable();
            $table->string('sello_imagen')->nullable();

            // ── Papel ───────────────────────────────────────────────────
            $table->string('tamano_papel')->default('carta');
            $table->string('orientacion')->default('vertical');

            /*
             * ── Lo que el ALUMNO puede hacer ────────────────────────────
             *
             * Que pueda descargarlo es una decisión de la escuela, no del
             * sistema: hay planteles donde el historial sólo se entrega en
             * ventanilla, sellado. Por eso el interruptor.
             *
             * Y cuando se le deja descargarlo, la copia lleva MARCA DE AGUA por
             * omisión. No es adorno: sin ella, un PDF idéntico al oficial anda
             * circulando sin sello ni firma autógrafa, y la escuela no tiene
             * cómo distinguir el que emitió del que alguien editó. La marca
             * dice en el documento mismo para qué NO sirve.
             */
            $table->boolean('descarga_alumno')->default(false);
            $table->boolean('marca_agua_alumno')->default(true);
            $table->string('marca_agua_texto')->default('No válido sin sello ni firma');

            $table->auditoria();

            /*
             * Uno por nivel. `deleted_at` va en el único porque la tabla usa
             * borrado lógico: sin él, retirar una variante y volver a crearla
             * chocaría contra la fila borrada, que sigue ahí.
             */
            $table->unique(['nivel_estudios_id', 'deleted_at'], 'diseno_historial_por_nivel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disenos_historial');
    }
};
