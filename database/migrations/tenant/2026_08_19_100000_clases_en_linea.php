<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clases en línea: el docente programa desde su materia y al alumno le aparece
 * el botón para entrar.
 *
 * La spec ya preveía `videoconferencias` («del legacy, Zoom integrado») con
 * `proveedor`, `meeting_id`, `url_join`, `inicio`, `fin` y `grabacion_ruta`. Se
 * implementa tal cual y se le agrega lo que el pedido del cliente exige y la
 * spec no contemplaba: el POOL de cuentas y el reparto entre ellas.
 *
 * ── Tres tablas, y cada una contesta una pregunta distinta ─────────────────
 * - `integraciones_videoconferencia` — ¿está encendido este proveedor y con qué
 *   credenciales habla? Una fila por proveedor. Es el equivalente de
 *   `pasarelas_pago` en el cobro, y por las mismas razones: las credenciales van
 *   cifradas y encender exige tenerlas completas.
 * - `cuentas_videoconferencia` — ¿con qué anfitriones se puede dar clase? Es el
 *   pool. En Zoom cada fila es una LICENCIA que sostiene una reunión a la vez;
 *   en Meet es la identidad que organiza el evento y no se agota. La diferencia
 *   la declara `ProveedoresVideoCatalogo`, no esta tabla.
 * - `videoconferencias` — la clase en sí, con su ventana y sus enlaces.
 *
 * ── Por qué la ventana se guarda con INICIO y FIN, y no con duración ───────
 * Porque la pregunta que hay que contestar al programar es «¿esta licencia está
 * libre entre las 9:00 y las 11:00?», y con una duración habría que calcular el
 * fin de cada fila candidata dentro del WHERE. Con las dos columnas, el traslape
 * es una comparación que el índice `(cuenta_id, inicio)` sostiene.
 *
 * ── `url_anfitrion` es una CREDENCIAL, no un enlace ────────────────────────
 * El `start_url` de Zoom entra como anfitrión SIN pedir contraseña: quien lo
 * tenga puede silenciar, expulsar y terminar la clase de otro. Vive aquí porque
 * hay que guardarlo, pero no sale nunca hacia un alumno —eso lo decide el
 * controlador, y por eso está escrito también ahí—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integraciones_videoconferencia', function (Blueprint $table) {
            $table->id();

            // zoom | meet. Los declara ProveedoresVideoCatalogo.
            $table->string('clave', 30)->unique();

            $table->boolean('activa')->default(false);

            /*
             * Cifradas en reposo, como las de las pasarelas y las del PAC. Un
             * `client_secret` de Zoom en claro en la base es una clase de
             * cualquier grupo a disposición de quien lea una tabla.
             */
            $table->text('credenciales')->nullable();

            $table->auditoria();
        });

        Schema::create('cuentas_videoconferencia', function (Blueprint $table) {
            $table->id();

            $table->string('proveedor', 30);

            // Cómo la llama la escuela: «Licencia 1», «Cuenta de posgrado».
            $table->string('etiqueta', 120);

            /*
             * Con qué la conoce el proveedor: el correo del usuario de Zoom, o
             * la cuenta de Workspace que organiza el evento. No se llama `email`
             * porque no todos los proveedores identifican por correo, y el día
             * que entre uno que use un id opaco esta columna sigue sirviendo.
             */
            $table->string('identificador', 190);

            /*
             * Apagada = no entra en el reparto. No se borra: las clases que ya
             * dio siguen colgando de ella, y borrarla dejaría sin explicación
             * los enlaces de un ciclo entero.
             */
            $table->boolean('activa')->default(true);

            $table->auditoria();

            // Una cuenta no se carga dos veces en el mismo proveedor.
            $table->unique(['proveedor', 'identificador']);
        });

        Schema::create('videoconferencias', function (Blueprint $table) {
            $table->id();

            $table->foreignId('asignatura_grupo_id')->constrained('asignatura_grupo')->cascadeOnDelete();

            /*
             * Con qué cuenta se dio. `nullOnDelete` no: las cuentas no se
             * borran, se apagan. Queda `restrictOnDelete` implícito por no
             * declarar cascada — si algún día se intenta borrar una cuenta con
             * clases, la base lo impide, que es lo correcto.
             */
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas_videoconferencia');

            /*
             * Se copia del catálogo en vez de leerse por la cuenta: una clase
             * pasada tiene que poder decir con qué se dio aunque su cuenta ya no
             * exista o haya cambiado de proveedor.
             */
            $table->string('proveedor', 30);

            $table->string('titulo', 180);

            // Lo que el proveedor nos devuelve para volver a preguntarle.
            $table->string('meeting_id', 120)->nullable();

            /*
             * Para el alumno. Es el que viaja a la pantalla y el único que puede
             * ver alguien que no sea el docente.
             */
            $table->text('url_join')->nullable();

            // Para el docente. Entra como anfitrión: NO es para el alumno.
            $table->text('url_anfitrion')->nullable();

            $table->dateTime('inicio');
            $table->dateTime('fin');

            // programada | en_curso | terminada | cancelada
            $table->string('estado', 20)->default('programada');

            // La graba el proveedor y se recoge después; la spec ya la preveía.
            $table->text('grabacion_ruta')->nullable();

            // Quién la programó. Es el docente, pero control escolar también
            // puede: por eso se guarda en vez de suponerse.
            $table->unsignedBigInteger('programada_por')->nullable();

            $table->auditoria();

            /*
             * El índice del traslape: «¿tiene esta cuenta algo que empiece antes
             * de que yo termine?». Empieza por `cuenta_id`, así que es también
             * el que sostiene su foránea y no hace falta otro.
             */
            $table->index(['cuenta_id', 'inicio']);

            // Y el de la pantalla: las clases de una materia, por fecha.
            $table->index(['asignatura_grupo_id', 'inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videoconferencias');
        Schema::dropIfExists('cuentas_videoconferencia');
        Schema::dropIfExists('integraciones_videoconferencia');
    }
};
