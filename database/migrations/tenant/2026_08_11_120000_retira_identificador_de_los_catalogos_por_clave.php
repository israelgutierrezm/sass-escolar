<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se retira `identificador` de los catálogos que ya viajan por su CLAVE.
 *
 * ── Cuáles y por qué ───────────────────────────────────────────────────────
 * Nivel de estudios, tipo de periodo, tipo de asignatura y tipo de
 * certificación: los cuatro que el XML del DEC lee ahora por `clave`. La
 * columna quedó sin un solo lector en todo el código —comprobado antes de
 * escribir esto—, y una columna que nadie lee pero que la pantalla sigue
 * ofreciendo es peor que no tenerla: alguien la captura pensando que sirve para
 * algo.
 *
 * En `tipos_asignatura` venía vacía desde el primer día, así que ahí no se
 * pierde ni un dato.
 *
 * ── Cuáles NO se tocan, y esto importa ─────────────────────────────────────
 * En el resto de catálogos `identificador` SIGUE VIVO y es el valor que espera
 * la SEP:
 *
 *   - `entidades_federativas` — su clave es la abreviatura de RENAPO («AS») y
 *     el DEC quiere el número («01»).
 *   - `campus`, `carreras`, `asignaturas` — su clave es el código interno de la
 *     escuela; el identificador es el oficial (`idCampus`, `idCarrera`,
 *     `idAsignatura`).
 *   - `generos` — `idGenero` (250 / 251).
 *   - `modalidades_titulacion`, `fundamentos_legales_servicio_social`, `cargos`
 *     — alimentan el XML del TÍTULO y ni siquiera tienen columna `clave`.
 *
 * Quitarles el identificador dejaría certificados y títulos sin esos atributos,
 * y eso no falla aquí: lo rechaza el web service de la SEP.
 *
 * ── Sin vuelta atrás con datos ─────────────────────────────────────────────
 * `down()` devuelve la columna vacía. Los valores no se pueden recuperar y
 * tampoco hacen falta: en estos cuatro eran una copia de la clave (o estaban en
 * blanco).
 */
return new class extends Migration
{
    private const TABLAS = ['niveles_estudio', 'tipos_periodo', 'tipos_asignatura', 'tipos_certificacion'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            // Se comprueba antes de actuar: un reintento tras un fallo parcial
            // no debe chocar contra su propio trabajo.
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'identificador')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('identificador');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasTable($tabla) && ! Schema::hasColumn($tabla, 'identificador')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->string('identificador')->nullable()->after('clave');
                });
            }
        }
    }
};
