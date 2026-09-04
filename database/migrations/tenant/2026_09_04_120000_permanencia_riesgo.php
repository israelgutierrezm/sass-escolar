<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El riesgo compuesto: los niveles configurables y su histórico por matrícula.
 *
 * ── Por qué el nivel es un CATÁLOGO y no un enum ───────────────────────────
 * Qué puntaje es «alto» lo decide la escuela, no el código. Cinco niveles con
 * umbrales cableados obligarían a todas a llamar «crítico» a lo mismo, y lo que
 * en un bachillerato de mil alumnos es una cola manejable, en una normal de
 * ciento veinte es media escuela. Los cinco del pedido se siembran como punto de
 * partida.
 *
 * ── Y `riesgo_matricula` es APPEND-ONLY, con una salvedad importante ───────
 * Cada cálculo que CAMBIA algo es una fila nueva, así que «conservar el cálculo
 * anterior» —que el pedido exige— sale gratis y el histórico existe sin tabla
 * aparte.
 *
 * Lo que NO se guarda es un renglón por matrícula por corrida: cinco mil alumnos
 * evaluados a diario son 1.8 millones de filas al año para almacenar «sigue
 * igual». Se escribe cuando el nivel o el puntaje se mueven, y eso convierte la
 * tabla en lo que de verdad interesa: la historia de los CAMBIOS.
 *
 * ── El riesgo NO es una columna de `matricula_oferta` ──────────────────────
 * Y eso es deliberado. Una columna ahí sería un atributo de la persona —«esta
 * alumna es de riesgo alto»— que se arrastra para siempre y que cualquier
 * pantalla puede leer sin contexto. Aquí es una fila FECHADA, con su desglose y
 * con la puerta abierta a que mañana valga otra cosa; y su permiso lo gobierna
 * quien la consulta, no la propia matrícula.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->niveles();
        $this->riesgo();
    }

    public function down(): void
    {
        Schema::dropIfExists('riesgo_matricula');
        Schema::dropIfExists('niveles_riesgo');
    }

    private function niveles(): void
    {
        if (Schema::hasTable('niveles_riesgo')) {
            return;
        }

        Schema::create('niveles_riesgo', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 50)->unique();
            $t->string('nombre', 150);
            $t->string('descripcion', 255)->nullable();

            /*
             * A partir de qué puntaje empieza este nivel. El más alto que
             * alcance el puntaje es el que gana, así que los umbrales no pueden
             * solaparse por definición: son un corte, no un rango.
             *
             * El primero va en 0 y es el que atrapa a quien no tiene nada: sin
             * él, una matrícula sin alertas no tendría nivel y habría que
             * inventarle uno al mirarla.
             */
            $t->unsignedSmallInteger('desde_puntaje');

            /*
             * Si a este nivel se le pide seguimiento. NO es una acción
             * automática —nada de este módulo la ejecuta— sino lo que la
             * pantalla usa para separar «esto hay que atenderlo» de «esto se
             * anota». Es una bandera de catálogo, como todas las demás.
             */
            $t->boolean('pide_seguimiento')->default(false);

            $t->string('color', 20)->default('gris');
            $t->unsignedSmallInteger('orden')->default(0);
            $t->boolean('activo')->default(true);
            $t->auditoria();
        });
    }

    private function riesgo(): void
    {
        if (Schema::hasTable('riesgo_matricula')) {
            return;
        }

        Schema::create('riesgo_matricula', function (Blueprint $t) {
            $t->id();
            $t->foreignId('matricula_oferta_id')->constrained('matricula_oferta');

            $t->timestamp('calculado_en');
            $t->foreignId('nivel_id')->constrained('niveles_riesgo');
            $t->unsignedSmallInteger('puntaje');

            /*
             * EL DESGLOSE, y es la mitad de la tabla.
             *
             * El pedido lo dice: «el nivel compuesto debe mostrar las señales
             * que lo forman» y «no guardes únicamente un puntaje sin
             * explicación». Aquí va categoría por categoría lo que aportó, con
             * las alertas concretas que lo produjeron y las que se descartaron
             * por doble conteo.
             *
             * Sin él, un «riesgo alto» es un número opaco: no se puede discutir,
             * ni corregir, ni explicarle a nadie. Y el pedido prohíbe
             * expresamente enseñarle al alumno un puntaje que no pueda entender.
             */
            $t->json('desglose');

            // De dónde venía. Null en el primero: no había anterior.
            $t->foreignId('nivel_anterior_id')->nullable()->constrained('niveles_riesgo');
            $t->unsignedSmallInteger('puntaje_anterior')->nullable();

            /*
             * ── EL AJUSTE HUMANO ───────────────────────────────────────────
             * Una persona autorizada puede fijar otro nivel, y **el calculado se
             * conserva**: las dos cifras quedan en la misma fila. Sobrescribir
             * el cálculo haría imposible saber que hubo un ajuste, y con eso se
             * pierde lo único que lo hace legítimo — que alguien se hizo
             * responsable de cambiarlo.
             *
             * El motivo es obligatorio cuando hay ajuste. Lo exige el servicio:
             * en la base sería nullable de todos modos porque la mayoría de las
             * filas no son ajustes.
             */
            $t->foreignId('nivel_ajustado_id')->nullable()->constrained('niveles_riesgo');
            $t->text('ajuste_motivo')->nullable();
            $t->unsignedBigInteger('ajustado_por')->nullable();
            $t->timestamp('ajustado_en')->nullable();

            $t->foreignId('corrida_id')->nullable()->constrained('corridas_evaluacion');

            $t->timestamps();

            /*
             * El índice que de verdad se usa: «el riesgo VIGENTE de esta
             * matrícula» es la fila más reciente, y eso se pregunta por cada
             * renglón de la bandeja.
             */
            $t->index(['matricula_oferta_id', 'calculado_en'], 'riesgo_vigente');
            $t->index('calculado_en');
        });
    }
};
