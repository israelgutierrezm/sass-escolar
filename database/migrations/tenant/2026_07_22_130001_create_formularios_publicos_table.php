<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicación de un formulario para embeberlo en la página web de la escuela.
 *
 * Es una tabla APARTE y no columnas en `formularios` porque son dos cosas
 * distintas: el formulario es QUÉ se pregunta —y está versionado y congelado en
 * cuanto alguien contesta—, y la publicación es CÓMO y DÓNDE se ofrece: con qué
 * token, en qué campaña, a quién se le asignan los que lleguen. La escuela
 * publica el mismo formulario dos veces —una para la feria y otra para la
 * página— y cada publicación mide por separado.
 *
 * `modo` es la decisión del cliente: cada publicación declara si solo capta
 * interés (deja un prospecto y promoción da seguimiento) o si permite la
 * inscripción autogestiva completa.
 *
 * El `token` es la URL pública. Va como UUID y no como un consecutivo porque
 * cualquiera en internet puede probar `/p/1`, `/p/2`: un id adivinable
 * convierte un formulario retirado en uno que sigue recibiendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formularios_publicos', function (Blueprint $table) {
            $table->id();
            // Apunta a una VERSIÓN concreta del formulario. Si la escuela
            // publica la v2, es otra publicación: así las respuestas de la v1
            // siguen queriendo decir lo que decían.
            $table->foreignId('formulario_id')->constrained('formularios');

            $table->uuid('token')->unique();
            $table->string('nombre', 150); // interno: "Campaña feria marzo"
            $table->string('modo', 20)->default('captacion'); // captacion / inscripcion

            // Lo que ve el visitante.
            $table->string('titulo', 200);
            $table->text('bienvenida')->nullable();
            $table->text('gracias')->nullable();

            // A qué se atribuye lo que entre por aquí. `origen_id` es lo que
            // permite después separar "llegaron solos" de "los capturamos".
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->foreignId('etapa_crm_id')->nullable()->constrained('etapas_crm');
            $table->foreignId('campus_id')->nullable()->constrained('campus');
            // Oferta fija de la campaña. En NULL, el visitante elige de la
            // lista de ofertas activas.
            $table->foreignId('oferta_id')->nullable()->constrained('oferta');
            // A quién se le asignan como titular los que lleguen. Sin esto, un
            // prospecto autogestivo cae en tierra de nadie y nadie lo llama.
            $table->foreignId('asesor_persona_id')->nullable()->constrained('asesores', 'persona_id');

            $table->boolean('activo')->default(true);
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();

            // Contador de visitas y de envíos: sin ellos no se puede saber si
            // una campaña convierte mal o simplemente no la vio nadie.
            $table->unsignedInteger('visitas')->default(0);
            $table->unsignedInteger('envios')->default(0);

            $table->auditoria();

            $table->index(['activo', 'formulario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formularios_publicos');
    }
};
