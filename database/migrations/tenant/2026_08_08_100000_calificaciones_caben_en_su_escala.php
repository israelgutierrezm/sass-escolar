<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Que la calificación quepa en la escala que la escuela configuró.
 *
 * Las columnas eran `decimal(4,2)` —cuatro dígitos, dos decimales—, y eso deja
 * fuera dos cosas que el sistema ya permite configurar:
 *
 * ── El 100 no cabía ────────────────────────────────────────────────────────
 * `decimal(4,2)` llega hasta 99.99. Una escuela que califique de 0 a 100 —hay
 * muchas— podía capturar un 99.9 pero NO un 100: el mejor resultado posible
 * reventaba con «Numeric value out of range», un error de base de datos en
 * mitad de un acta y sin nada que explicara qué había pasado.
 *
 * ── El tercer decimal se perdía en silencio ────────────────────────────────
 * Peor, porque no avisa: la pantalla acepta configurar tres decimales y la
 * validación deja pasar un 8.756, pero la columna sólo guarda dos y MySQL lo
 * redondea a 8.76 sin protestar. Quien capturaba veía su número aceptado y en
 * el kárdex aparecía otro.
 *
 * `decimal(6,3)` da hasta 999.999: cubre las escalas de 0-10, 0-100 y las de
 * puntos, con los tres decimales que la configuración promete.
 *
 * ── Ampliar no pierde datos ────────────────────────────────────────────────
 * Se pasa a un tipo que contiene al anterior: todo lo guardado sigue valiendo
 * exactamente lo mismo. Por eso no hace falta convertir nada ni hay vuelta
 * atrás peligrosa —aunque `down()` sí la tiene, y por eso avisa—.
 */
return new class extends Migration
{
    /** Las tres columnas donde vive una calificación. */
    private const COLUMNAS = [
        ['historial', 'calificacion'],
        ['inscripcion', 'calificacion_final'],
        ['calificaciones_componente', 'calificacion'],
    ];

    public function up(): void
    {
        /*
         * Se usa SQL directo y no `$table->decimal(...)->change()` porque
         * `change()` necesita doctrine/dbal y arrastra el resto de atributos de
         * la columna; aquí sólo se quiere ampliar el tipo.
         */
        foreach (self::COLUMNAS as [$tabla, $columna]) {
            DB::statement("ALTER TABLE `{$tabla}` MODIFY `{$columna}` DECIMAL(6,3) NULL");
        }
    }

    public function down(): void
    {
        /*
         * Volver atrás SÍ pierde datos: cualquier calificación de 100 o más, o
         * con tres decimales, se corta. Se deja porque una migración debe poder
         * revertirse, pero quien la ejecute tiene que saberlo.
         */
        foreach (self::COLUMNAS as [$tabla, $columna]) {
            DB::statement("ALTER TABLE `{$tabla}` MODIFY `{$columna}` DECIMAL(4,2) NULL");
        }
    }
};
