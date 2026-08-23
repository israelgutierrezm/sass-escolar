<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `configuraciones.descripcion` era `varchar(255)` y la copia del catálogo no
 * cabía.
 *
 * ── Por qué es un defecto y no una molestia de captura ────────────────────
 * Esa columna NO la escribe nadie a mano: `Ajustes::guardar()` copia ahí la
 * descripción declarada en `CatalogoAjustes` cada vez que se guarda el ajuste.
 * O sea que el largo del texto de un archivo PHP decide si la pantalla de
 * configuración puede guardar: pasarse de 255 caracteres hace que mover ese
 * interruptor reviente con un `Data too long` de MySQL en la cara del usuario,
 * y el interruptor queda sin poderse encender NUNCA. Salió al escribir el de la
 * bolsa de trabajo, que explica lo que pasa en los dos estados y ocupa 256
 * caracteres —uno de más—.
 *
 * Se pasa a TEXT: es documentación, no un dato que se consulte ni se indexe, y
 * el límite estaba puesto por costumbre y no por una razón.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('configuraciones', 'descripcion')) {
            return;
        }

        // Sin `doctrine/dbal` en el proyecto, el cambio de tipo va en SQL. Es
        // idempotente: repetir un MODIFY al mismo tipo no falla.
        DB::statement('ALTER TABLE `configuraciones` MODIFY `descripcion` TEXT NULL');
    }

    public function down(): void
    {
        // Volver a 255 truncaría lo que ya no cabe; se recorta a propósito para
        // que la vuelta atrás no reviente a mitad de camino.
        DB::statement('UPDATE `configuraciones` SET `descripcion` = LEFT(`descripcion`, 255)');
        DB::statement('ALTER TABLE `configuraciones` MODIFY `descripcion` VARCHAR(255) NULL');
    }
};
