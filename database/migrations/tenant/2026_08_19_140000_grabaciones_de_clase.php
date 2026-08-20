<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las grabaciones de las clases en línea, archivadas donde la escuela diga.
 *
 * ── Una clase produce VARIOS archivos, no uno ──────────────────────────────
 * Zoom devuelve el video, el audio por separado, el chat y a veces la
 * transcripción. Por eso esto es una tabla hija y no una columna: con un solo
 * `grabacion_ruta` habría que elegir cuál de los cuatro guardar y tirar el
 * resto, y el chat de una clase es justo lo que alguien va a buscar seis meses
 * después.
 *
 * La columna `videoconferencias.grabacion_ruta` que preveía la spec se queda
 * como está y sin uso nuevo: se conserva para no romper nada que la lea.
 *
 * ── El archivado es un TRABAJO, y por eso tiene estado ─────────────────────
 * Descargar un video de dos horas de Zoom y subirlo a Drive tarda minutos y
 * puede fallar a la mitad. Sin estado, un fallo se ve igual que «todavía no
 * empieza», y nadie sabe si esperar o reintentar. Con `intentos` y `error` se
 * puede reintentar lo que falló sin volver a bajar lo que ya está.
 *
 * ── `id_externo` es único por origen ───────────────────────────────────────
 * Es lo que hace idempotente el archivado. Zoom reenvía su webhook si no le
 * contestamos rápido, y Meet se consulta cada tanto: sin esta llave, la misma
 * clase se archivaría tres veces y se pagaría tres veces el almacenamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinos_grabacion', function (Blueprint $table) {
            $table->id();

            // disco | drive | dropbox. Los declara DestinosGrabacionCatalogo.
            $table->string('clave', 30)->unique();

            /*
             * Sólo uno puede estar activo. No lo impone la base —un único
             * parcial no es portable— sino el controlador, que apaga los demás
             * al encender uno. Con dos, habría que decidir qué enlace se le
             * enseña al alumno y se pagaría dos veces el mismo archivo.
             */
            $table->boolean('activo')->default(false);

            // Cifradas, como las del proveedor de video y las del PAC.
            $table->text('credenciales')->nullable();

            $table->auditoria();
        });

        Schema::create('grabaciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('videoconferencia_id')->constrained('videoconferencias')->cascadeOnDelete();

            // zoom | meet. Se copia y no se lee por la clase, por lo mismo que
            // allá: una grabación vieja debe poder decir de dónde salió.
            $table->string('origen', 30);

            /*
             * Con qué la conoce el proveedor. Único junto con el origen: es la
             * red que impide archivar dos veces lo mismo cuando Zoom reenvía su
             * aviso o cuando la consulta de Meet vuelve a pasar.
             */
            $table->string('id_externo', 190);

            // video | audio | chat | transcripcion | otro
            $table->string('tipo', 20)->default('video');

            $table->string('nombre', 190);
            $table->unsignedBigInteger('bytes')->nullable();

            // pendiente | archivando | archivada | fallida
            $table->string('estado', 20)->default('pendiente');

            /*
             * A dónde fue a parar. Se guarda en la fila y no se lee del destino
             * activo: cambiar de destino no debe reescribir dónde está lo que ya
             * se archivó — el archivo sigue en Drive aunque hoy se use Dropbox.
             */
            $table->string('destino', 30)->nullable();
            $table->text('ruta_destino')->nullable();
            $table->text('url_destino')->nullable();

            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('archivada_en')->nullable();

            /*
             * Si la ve el alumno.
             *
             * Apagada por omisión, y es deliberado: una clase grabada contiene
             * las caras y las voces de menores de edad, y publicarla es una
             * decisión de la escuela sobre datos personales — no algo que deba
             * pasar solo porque alguien encendió el archivado.
             */
            $table->boolean('visible_alumnos')->default(false);

            $table->auditoria();

            $table->unique(['origen', 'id_externo']);
            // Las grabaciones de una clase, que es como se consultan.
            $table->index(['videoconferencia_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grabaciones');
        Schema::dropIfExists('destinos_grabacion');
    }
};
