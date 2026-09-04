<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las reglas de alerta: a quién alcanzan y qué exigen.
 *
 * ── Dos tablas, por la misma razón que las reglas formativas ───────────────
 * Una regla dice A QUIÉN aplica; sus VERSIONES dicen QUÉ mide y con qué umbral.
 * Son dos preguntas con vidas distintas: el alcance de «asistencia baja en
 * bachillerato» no cambia nunca y su umbral cambia cada reforma. En una sola
 * tabla, mover el umbral de 80 a 75 obligaría a duplicar el alcance, y dos
 * filas diciendo a quién aplican lo mismo acaban divergiendo.
 *
 * Y hace falta de verdad aquí, no por simetría: **la alerta guarda
 * `regla_version_id`**, así que dentro de dos años se puede contestar «con qué
 * umbral se generó esto» aunque la regla haya cambiado tres veces. Sin
 * versiones, cambiar un umbral reescribiría la historia de todas las alertas
 * que se generaron con el anterior.
 *
 * ── El alcance NO es el resolutor de servicio social ───────────────────────
 * En `ProcesosFormativos`, `ResolutorDeRegla` elige UNA regla: la más
 * específica gana y las demás no existen. **Aquí es al revés: todas las reglas
 * que alcanzan a un alumno se evalúan**, porque «tres faltas seguidas» y
 * «promedio bajo el umbral» son dos preguntas distintas y las dos pueden ser
 * ciertas a la vez. Por eso no hay pesos ni jerarquía lexicográfica: hay
 * `alcanzaA()`. Escrito con el resolutor, un alumno recibiría una sola alerta
 * —la de la regla más específica— y las demás señales desaparecerían sin que
 * nadie lo notara.
 *
 * La ÚNICA desambiguación es entre versiones de la MISMA regla: ahí sí gana la
 * vigente, y con empate, la más reciente.
 *
 * ── Por qué no hay un campo de CONDICIÓN libre ─────────────────────────────
 * La tentación es una caja donde la escuela escriba `faltas >= 3 AND dias <= 7`.
 * Se rechaza por lo mismo que se rechazó el campo de SQL del constructor de
 * reportes —es una superficie de ejecución que ninguna lista negra cierra— y
 * por algo más importante: **una expresión arbitraria no se puede EXPLICAR**.
 * Una alerta tiene que decir por qué se generó, y de una expresión libre sólo
 * se puede repetir el texto. Aquí una regla es
 * `(métrica, comparador, umbral, ventana)`, que se lee en una frase.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reglas();
        $this->versiones();
        $this->exclusiones();
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusiones_regla_alerta');
        Schema::dropIfExists('reglas_alerta_versiones');
        Schema::dropIfExists('reglas_alerta');
    }

    private function reglas(): void
    {
        if (Schema::hasTable('reglas_alerta')) {
            return;
        }

        Schema::create('reglas_alerta', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 180);
            $t->string('descripcion', 500)->nullable();

            $t->foreignId('categoria_id')->constrained('categorias_senal');

            /*
             * Qué proveedor de señales la calcula. Es una CLAVE de código
             * —`asistencia`, `academico`, `lms`…— y no una foránea, porque cada
             * proveedor es una clase: una fila nueva en una tabla no sabría
             * consultar nada. Mismo criterio que `tipos_actividad`.
             */
            $t->string('proveedor', 50);

            /*
             * ── EJES DE ALCANCE ────────────────────────────────────────────
             * Lo que se deja en NULL no acota: una regla sin ningún eje es la
             * general de la escuela. Es lo que permite que la general y la
             * excepción convivan sin escribir la general dos veces.
             *
             * La lista es CERRADA y es académica y administrativa. No hay
             * —ni va a haber— ejes de sexo, nacionalidad, beca ni ningún otro
             * atributo sensible: eso convertiría una política de equidad en
             * una marca, y es una de las tres prohibiciones duras del módulo.
             */
            $t->foreignId('campus_id')->nullable()->constrained('campus');
            $t->unsignedBigInteger('nivel_estudios_id')->nullable();
            $t->foreignId('programa_academico_id')->nullable()->constrained('programas_academicos');
            $t->foreignId('plan_id')->nullable()->constrained('planes_estudio');
            $t->foreignId('ciclo_id')->nullable()->constrained('ciclos');

            // La situación de la matrícula: activo, condicionado… Sirve para
            // reglas que sólo aplican a quien ya viene arrastrando algo.
            $t->foreignId('situacion_alumno_id')->nullable()->constrained('situaciones_alumno');

            $t->string('modalidad', 50)->nullable();
            $t->unsignedSmallInteger('generacion_desde')->nullable();
            $t->unsignedSmallInteger('generacion_hasta')->nullable();

            // Para las señales que son POR MATERIA: acotar a una asignatura
            // concreta —la que reprueba media generación— sin tocar las demás.
            $t->foreignId('asignatura_id')->nullable()->constrained('asignaturas');

            $t->boolean('activa')->default(true);
            $t->text('notas')->nullable();
            $t->auditoria();

            $t->index(['activa', 'categoria_id']);
            $t->index('proveedor');
        });
    }

    private function versiones(): void
    {
        if (Schema::hasTable('reglas_alerta_versiones')) {
            return;
        }

        Schema::create('reglas_alerta_versiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('regla_id')->constrained('reglas_alerta');
            $t->unsignedSmallInteger('version');

            $t->date('vigente_desde');
            $t->date('vigente_hasta')->nullable();

            // ── QUÉ SE MIDE ──────────────────────────────────────────────
            // La métrica es una clave que el proveedor declara. Una que él no
            // conozca se rehúsa al guardar: si no, la regla se guardaría, no
            // mediría nada y quien la escribió creería que sí.
            $t->string('metrica', 80);
            $t->string('comparador', 10);       // >=, >, <=, <, ==, !=
            $t->decimal('umbral', 12, 4)->nullable();

            /*
             * De dónde sale el umbral.
             *
             * `fijo` lo toma de la columna de arriba. `plan` lo lee del plan de
             * estudios —hoy, la calificación mínima aprobatoria—, que es donde
             * ya vive: copiarlo aquí crearía un segundo número que se separaría
             * del real en cuanto alguien corrigiera el plan, y entonces la
             * alerta diría que va reprobando quien no, o al revés.
             */
            $t->string('umbral_fuente', 20)->default('fijo');

            // ── SOBRE QUÉ PERIODO ────────────────────────────────────────
            $t->string('ventana_tipo', 30)->default('ciclo');   // ciclo | ultimos_dias | desde_inicio
            $t->unsignedSmallInteger('ventana_valor')->nullable();

            /*
             * ── COBERTURA MÍNIMA ─────────────────────────────────────────
             * Cuántos datos hacen falta para que la regla se atreva a opinar.
             *
             * Es la columna que impide el defecto más caro de este módulo:
             * medido contra el demo, `asistencia_clase` tiene 8 filas para 17
             * inscripciones, así que el porcentaje se calcula sobre lo
             * REGISTRADO y no sobre el calendario. Sin cobertura mínima, a
             * quien tiene una sola sesión con falta se le calcula «0 % de
             * asistencia» y se le levanta una alerta que no significa nada.
             *
             * Bajo la cobertura, el resultado NO es `no_dispara`: es
             * `sin_datos`, que es un tercer valor y no un no.
             */
            $t->unsignedSmallInteger('cobertura_minima')->default(0);

            // ── QUÉ TAN GRAVE Y CUÁNTO PESA ──────────────────────────────
            $t->string('severidad', 20)->default('bajo');       // informativo|bajo|medio|alto|critico
            $t->unsignedSmallInteger('peso')->default(1);

            // ── CADA CUÁNDO Y CADA CUÁNTO SE REPITE ──────────────────────
            $t->string('frecuencia', 20)->default('diaria');    // diaria|semanal|por_evento

            /*
             * Enfriamiento: tras CERRARSE una alerta de esta regla para este
             * alumno, cuántos días no se vuelve a levantar.
             *
             * No es lo mismo que la deduplicación —aquélla impide una segunda
             * mientras la primera sigue abierta—: esto impide el REBOTE de una
             * asistencia que oscila alrededor del umbral y que, sin
             * enfriamiento, abriría y cerraría una alerta cada semana.
             */
            $t->unsignedSmallInteger('cooldown_dias')->default(14);

            // ── QUÉ SE ESPERA DE QUIEN LA ATIENDE ────────────────────────
            $t->unsignedSmallInteger('sla_horas')->nullable();
            $t->unsignedBigInteger('responsable_rol_id')->nullable();

            /*
             * A quién se le avisa. Por omisión, a NADIE.
             *
             * Una regla recién escrita que empiece a mandar avisos el primer
             * día los manda sobre datos a medio cargar, y a la tercera nadie
             * los lee. Encender el aviso es un acto aparte de encender la
             * regla: primero se mira la cola, luego se avisa.
             */
            $t->boolean('avisa_al_alumno')->default(false);
            $t->boolean('avisa_a_la_escuela')->default(false);
            $t->string('plantilla_aviso', 500)->nullable();

            $t->text('notas')->nullable();
            $t->auditoria();

            $t->unique(['regla_id', 'version']);
            $t->index(['regla_id', 'vigente_desde']);
        });
    }

    /**
     * A quién NO se le aplica una regla, aunque le alcance.
     *
     * Un alumno con una situación conocida y autorizada —una licencia médica,
     * un permiso de la dirección— no tiene por qué aparecer en la cola cada
     * lunes para que alguien lo descarte otra vez. Descartar la alerta una vez
     * no basta: mañana vuelve a nacer.
     *
     * Y es un ACTO con dueño, no una casilla: lleva su motivo obligatorio y
     * quién la autorizó, porque dentro de un año alguien va a preguntar por qué
     * esta persona no aparecía en ningún reporte. Es el molde de
     * `excepciones_expediente`.
     */
    private function exclusiones(): void
    {
        if (Schema::hasTable('exclusiones_regla_alerta')) {
            return;
        }

        Schema::create('exclusiones_regla_alerta', function (Blueprint $t) {
            $t->id();

            // NULL = de TODAS las reglas. Es el caso de la licencia médica: no
            // se excluye de la regla de asistencia, se excluye del módulo.
            $t->foreignId('regla_id')->nullable()->constrained('reglas_alerta');

            $t->foreignId('matricula_oferta_id')->constrained('matricula_oferta');

            $t->text('motivo');
            $t->date('vigente_hasta')->nullable();
            $t->unsignedBigInteger('autorizada_por')->nullable();
            $t->auditoria();

            $t->index(['matricula_oferta_id', 'regla_id']);
        });
    }
};
