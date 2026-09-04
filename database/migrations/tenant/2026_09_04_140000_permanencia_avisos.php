<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El rastro de lo que este módulo ya avisó.
 *
 * ── Para qué sirve, y por qué se escribe PRIMERO ───────────────────────────
 * Sin él, el comando diario volvería a avisar cada mañana mientras la condición
 * siga siendo cierta — y un recordatorio que llega treinta días seguidos deja
 * de leerse al tercero. Su índice ÚNICO es lo que DECIDE: si ya existe, la
 * inserción revienta ahí y no se levanta ningún aviso.
 *
 * Al revés —crear el aviso y luego intentar el rastro— dos corridas simultáneas
 * dejarían dos avisos y un solo rastro. Y **no basta un `SELECT` previo**: lo
 * pasan las dos. Es exactamente la forma de `alertas_proceso`.
 *
 * ── El SUJETO es una columna GENERADA, y hace falta ────────────────────────
 * Un aviso cuelga de un CASO o de una MATRÍCULA, nunca de los dos, así que las
 * dos foráneas son nullable. Un único sobre `(caso_id, matricula_oferta_id,
 * evento, referencia)` **no protegería nada**: MySQL considera distintas dos
 * filas con NULL en cualquier columna del único, así que el mismo aviso se
 * podría insertar dos veces. La columna generada colapsa las dos foráneas en un
 * texto sin NULL, y el único va sobre ella. Misma defensa que
 * `alertas.clave_dedup` y `casos_permanencia.matricula_si_abierto`.
 *
 * ── TRAMPA que costó encontrar: la columna generada PROHÍBE el `CASCADE` ───
 * Una foránea con acción referencial —`ON DELETE CASCADE` o `SET NULL`— **no se
 * puede poner sobre una columna de la que DEPENDE una columna generada**. MySQL
 * lo rechaza con «1215 Cannot add foreign key constraint», que no dice nada de
 * columnas generadas y manda a buscar en la dirección equivocada: tipos, índices
 * y motores, que aquí estaban todos bien.
 *
 * Se midió probando las cuatro combinaciones sobre esta misma tabla:
 *
 *   caso_id       CASCADE   → falla        aviso_id      SET NULL  → pasa
 *   matricula_id  CASCADE   → falla        matricula_id  sin acción → pasa
 *
 * `aviso_id` pasa porque `sujeto` NO depende de él. Las otras dos van
 * `constrained()` a secas, como `adeudos`. La consecuencia es explícita y
 * buscada: **borrar un caso con rastro se rehúsa**, y quien limpie tiene que
 * decidir en qué orden — que es lo correcto, porque un rastro es un hecho.
 *
 * Y por eso la generada va DENTRO del `CREATE TABLE`: agregarla después obliga a
 * MySQL a reconstruir la tabla, y al rehacer las foráneas se topa con lo mismo.
 * Las otras dos de este módulo —`alertas` y `casos_permanencia`— se agregaron
 * con `ALTER` y funcionaron porque sus foráneas no llevan acción referencial.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Comprobar antes de actuar, y **por PIEZA**: si la tabla existiera a
         * medias —de un intento anterior que falló— un `if` alrededor de todo
         * el bloque la daría por buena y la migración quedaría marcada como
         * aplicada SIN su único. Es la lección que dejó el CHECK de movilidad.
         */
        if (Schema::hasTable('avisos_permanencia')) {
            return;
        }

        Schema::create('avisos_permanencia', function (Blueprint $t) {
            $t->id();

            /*
             * Uno de los dos, nunca los dos. Sin CHECK y **sin acción
             * referencial**: lo primero porque una columna con CHECK no admite
             * foránea con acción —MySQL 3823, la trampa de movilidad—, y lo
             * segundo por lo dicho arriba, que es de lo que depende `sujeto`.
             */
            $t->foreignId('caso_id')->nullable()->constrained('casos_permanencia');

            $t->foreignId('matricula_oferta_id')->nullable()->constrained('matricula_oferta');

            /** Qué se avisó. Las claves viven en `AvisoPermanencia`. */
            $t->string('evento', 40);

            /*
             * Qué lo hace único DENTRO de su evento: la fecha del vencimiento,
             * el id de la señal, el de la tarea. Nunca la hora — dos corridas
             * del mismo día tienen que chocar, que es de lo que se trata.
             */
            $t->string('referencia', 60)->default('');

            /*
             * El aviso que se levantó. NULLABLE y con `nullOnDelete`: borrar un
             * aviso NO puede resucitar el rastro. Sin eso, quien borre un aviso
             * viejo haría que el comando volviera a avisar de lo mismo a la
             * mañana siguiente.
             */
            $t->foreignId('aviso_id')->nullable()
                ->constrained('avisos')->nullOnDelete();

            $t->timestamp('emitida_en');

            /** A cuántas personas se les dirigió. Cero es un dato, no un fallo. */
            $t->unsignedSmallInteger('destinatarios')->default(0);

            $t->string('sujeto', 48)
                ->storedAs("concat(coalesce(caso_id, 0), ':', coalesce(matricula_oferta_id, 0))");

            $t->auditoria();

            $t->unique(['sujeto', 'evento', 'referencia'], 'aviso_permanencia_unico');

            /** Para «¿de qué se avisó hoy?», que es lo que el comando reporta. */
            $t->index('emitida_en');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_permanencia');
    }
};
