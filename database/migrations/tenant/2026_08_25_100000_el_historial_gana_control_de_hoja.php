<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las perillas de HOJA del historial impreso: márgenes, tipografía, saltos,
 * marca de agua y color.
 *
 * ── Por qué ahora y no antes ──────────────────────────────────────────────
 * Mientras el documento lo dibujaba el navegador, casi nada de esto se podía
 * cumplir: el diálogo de impresión de cada quien mandaba sobre los márgenes y
 * sobre la escala. Ahora que lo arma el servidor con mpdf, lo que se declare
 * aquí es lo que sale, igual en todas las computadoras de la escuela.
 *
 * ── Márgenes ─────────────────────────────────────────────────────────────
 * En milímetros, que es como los piensa quien compra el papel. El de arriba
 * nace en 40 porque tiene que caber el membrete CON logo; una escuela que
 * imprime sobre papel ya membretado lo sube a 60, y una sin logo lo baja. Antes
 * eran `14mm 12mm` cableados en el CSS y el membrete con logo se les encimaba.
 *
 * ── Tipografía ───────────────────────────────────────────────────────────
 * Familia, tamaño e interlineado. `fuente` guarda una FAMILIA GENÉRICA y no el
 * nombre de una tipografía: mpdf sólo tiene embebidas las suyas, y guardar
 * «Arial» daría un documento que se dibuja con otra cosa sin avisar. Tres
 * valores, que son los que un documento oficial usa.
 *
 * ── Salto por bloque ─────────────────────────────────────────────────────
 * «Cada periodo en hoja nueva». Hay escuelas que entregan el historial por
 * semestres sueltos, y hasta ahora la única palanca de maqueta era
 * `bloques_por_fila`.
 *
 * ── Marca de agua ────────────────────────────────────────────────────────
 * `marca_agua_ventanilla` es el hueco que más se notaba: sólo existía
 * `marca_agua_alumno`, así que la copia de MOSTRADOR no podía llevar «COPIA»
 * aunque la escuela lo pidiera. Y la opacidad se vuelve dato: 9 % se pierde en
 * una impresora láser vieja y se ve demasiado en una buena.
 *
 * ── Color ────────────────────────────────────────────────────────────────
 * El documento tenía `#eef2f7` y `#64748b` cableados. En un producto donde cada
 * escuela escoge su acento —y del que ya se retiró el morado fijo de 31
 * sitios— eso es lo mismo otra vez. Es un interruptor y no un color propio: el
 * acento ya está elegido en otro lado, y pedirlo dos veces es pedir que alguien
 * los desincronice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disenos_historial', function (Blueprint $tabla) {
            // Comprobado por PIEZA y no por bloque: un reintento tras un fallo
            // a la mitad no debe chocar contra su propio trabajo.
            if (! Schema::hasColumn('disenos_historial', 'margen_superior')) {
                $tabla->unsignedSmallInteger('margen_superior')->default(40)->after('orientacion');
                $tabla->unsignedSmallInteger('margen_inferior')->default(18)->after('margen_superior');
                $tabla->unsignedSmallInteger('margen_izquierdo')->default(12)->after('margen_inferior');
                $tabla->unsignedSmallInteger('margen_derecho')->default(12)->after('margen_izquierdo');
            }

            if (! Schema::hasColumn('disenos_historial', 'fuente')) {
                $tabla->string('fuente', 20)->default('sans')->after('margen_derecho');
                // Décimas: 8.5 pt es un tamaño real de documento oficial.
                $tabla->decimal('tamano_fuente', 3, 1)->default(9.0)->after('fuente');
                $tabla->decimal('interlineado', 2, 1)->default(1.3)->after('tamano_fuente');
            }

            if (! Schema::hasColumn('disenos_historial', 'salto_por_bloque')) {
                $tabla->boolean('salto_por_bloque')->default(false)->after('bloques_por_fila');
            }

            if (! Schema::hasColumn('disenos_historial', 'marca_agua_ventanilla')) {
                $tabla->boolean('marca_agua_ventanilla')->default(false)->after('marca_agua_alumno');
                $tabla->unsignedTinyInteger('marca_agua_opacidad')->default(9)->after('marca_agua_texto');
            }

            if (! Schema::hasColumn('disenos_historial', 'usa_color_acento')) {
                $tabla->boolean('usa_color_acento')->default(true)->after('marca_agua_opacidad');
            }
        });
    }

    public function down(): void
    {
        Schema::table('disenos_historial', function (Blueprint $tabla) {
            foreach ([
                'margen_superior', 'margen_inferior', 'margen_izquierdo', 'margen_derecho',
                'fuente', 'tamano_fuente', 'interlineado',
                'salto_por_bloque', 'marca_agua_ventanilla', 'marca_agua_opacidad', 'usa_color_acento',
            ] as $columna) {
                if (Schema::hasColumn('disenos_historial', $columna)) {
                    $tabla->dropColumn($columna);
                }
            }
        });
    }
};
