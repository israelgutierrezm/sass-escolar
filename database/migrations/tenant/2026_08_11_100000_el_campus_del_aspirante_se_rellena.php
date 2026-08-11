<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los aspirantes sin campus lo heredan de su oferta de interés.
 *
 * ── Por qué ahora ─────────────────────────────────────────────────────────
 * El campus pasa a ser obligatorio al capturar un prospecto, porque de él
 * depende a quién se le reparte: el turno va entre los asesores DE SU CAMPUS.
 * Sin este relleno, los que ya estaban se quedarían fuera de cualquier reparto
 * por un dato que nadie les capturó.
 *
 * ── La columna se queda NULLABLE ──────────────────────────────────────────
 * Y es a propósito. Un prospecto puede haber llegado por una vía que no sabe de
 * campus —una importación vieja, un formulario público de antes—, y volver la
 * columna NOT NULL obligaría a inventarle uno para poder migrar. Lo obligatorio
 * es CAPTURARLO: la regla vive en `GuardarAspiranteRequest`, donde se puede
 * decir «elige el campus» en vez de reventar con un error de base de datos.
 *
 * Lo que no se pueda rellenar se queda sin campus y entra en el reparto
 * general, que es mejor que quedarse sin dueño.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'UPDATE aspirantes a
             JOIN oferta o ON o.id = a.oferta_interes_id
             SET a.campus_id = o.campus_id
             WHERE a.campus_id IS NULL AND o.campus_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // No se deshace: no hay forma de saber cuáles se rellenaron aquí y
        // cuáles ya traían campus, y vaciarlos todos sería peor que el estado
        // anterior.
    }
};
