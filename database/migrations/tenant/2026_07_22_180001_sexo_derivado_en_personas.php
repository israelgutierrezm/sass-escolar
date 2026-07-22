<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `personas.sexo_id` deja de ser obligatorio: pasa a ser un dato DERIVADO.
 *
 * El cliente lo dijo directo: el formulario pedía «sexo» y «género» y para
 * quien captura son lo mismo, dos veces la misma pregunta. Se le quita la
 * pregunta, pero NO se borra la columna, y la diferencia importa:
 *
 * - **Sexo** es el dato legal binario (H/M). Es lo que la CURP codifica en su
 *   posición 11 y lo que los formatos de la SEP piden al titular. Cuando llegue
 *   el módulo de titulación, ese campo se necesita.
 * - **Género** es autoidentificado y tiene cinco opciones, incluida «prefiere no
 *   decir». No sirve para un trámite oficial y no debe usarse como si sirviera.
 *
 * Así que se conserva el campo y se cambia de dónde sale: de la CURP cuando hay
 * CURP —es la fuente autoritativa— y del género cuando este es inequívoco. Si
 * no hay ninguno de los dos, queda en null: es más honesto un hueco que
 * inventarle un sexo a alguien para satisfacer un NOT NULL.
 *
 * Ese NOT NULL es justo lo que obligaba a preguntarlo en seis pantallas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('sexo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver a NOT NULL exige que no haya nulos; se rellenan con el sexo
        // que la CURP ya conoce, y lo que quede sin resolver no puede volver
        // atrás sin inventar datos. Por eso el down solo intenta el cambio.
        Schema::table('personas', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('sexo_id')->nullable(false)->change();
        });
    }
};
