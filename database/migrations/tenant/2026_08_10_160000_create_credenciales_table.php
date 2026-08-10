<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * credenciales (TENANT) — la emisión: a quién se le entregó una credencial.
 *
 * ── Por qué hace falta una tabla si la credencial se dibuja al vuelo ───────
 * Por el QR. El código lleva una dirección, y esa dirección tiene que
 * identificar a la persona SIN ser adivinable: `/credencial/351` invita a pedir
 * 352, 353… y a llevarse el nombre, la foto y la carrera de la escuela entera.
 * Un uuid no se cuenta. Es la misma razón por la que `imagenes_contenido` se
 * sirve por uuid y no por id.
 *
 * Y como el QR se imprime, el identificador tiene que ser ESTABLE: el mismo
 * mañana que hoy. Firmar la URL con la llave de la aplicación habría evitado la
 * tabla, pero deja todas las credenciales impresas inservibles el día que se
 * rote `APP_KEY` —que es justo lo que se hace cuando hay una filtración—.
 *
 * ── Una fila por CREDENCIAL, no por persona ────────────────────────────────
 * Quien estudia dos carreras tiene dos credenciales y cada una lleva su propio
 * QR: el de la matrícula de Derecho no debe abrir la ficha de la de Medicina.
 *
 * ── Lo que esta tabla NO guarda ────────────────────────────────────────────
 * Ni el diseño, ni los campos, ni la vigencia: todo eso vive en
 * `credenciales_rol` y se resuelve al dibujar. Copiarlo aquí congelaría la
 * credencial el día que se emitió y el cambio de logo de la escuela no
 * alcanzaría a nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();

            /*
             * De qué ROL es esta credencial.
             *
             * No es decoración: quien da clases y además estudia un posgrado
             * tiene dos, y la de docente no lleva matrícula que la distinga.
             * Sin esta columna, al leer el QR no habría forma de saber qué
             * configuración aplicarle —ni siquiera si la escuela dejó ese QR
             * abierto—, y preguntarle su rol ACTIVO daría una respuesta
             * distinta cada vez que esa persona conmuta.
             */
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();

            // Nula para quien no es alumno: un docente tiene credencial y no
            // tiene matrícula.
            $table->foreignId('matricula_oferta_id')->nullable()
                ->constrained('matricula_oferta')->cascadeOnDelete();

            $table->timestamp('emitida_en')->useCurrent();

            $table->auditoria();

            /*
             * El índice es de BÚSQUEDA, no único.
             *
             * Un único no serviría de red: MySQL considera distintos dos NULL,
             * así que no ataría las credenciales sin matrícula —las de docentes
             * y administrativos, justo donde `matricula_oferta_id` es nula—.
             * La unicidad la sostiene el `firstOrCreate` del registro, y si dos
             * peticiones simultáneas llegaran a crear dos uuid para la misma
             * persona, ambos resuelven a la misma ficha: sobra un renglón, no
             * se rompe nada. No es el caso de `adeudos`, donde un duplicado es
             * un cobro de más y por eso ahí sí hay único.
             */
            $table->index(['persona_id', 'rol_id', 'matricula_oferta_id'], 'credenciales_de_la_persona');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciales');
    }
};
