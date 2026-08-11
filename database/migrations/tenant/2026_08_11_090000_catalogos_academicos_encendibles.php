<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel de estudios y tipo de periodo se pueden apagar.
 *
 * ── Por qué apagar y no borrar ─────────────────────────────────────────────
 * Estos catálogos vienen sembrados con los valores OFICIALES de la SEP —los
 * diez niveles, los siete tipos de periodo—, y una escuela usa dos o tres. El
 * resto no estorba en la base pero sí en cada desplegable: quien da de alta una
 * carrera en un bachillerato no debería tener que saltarse «Doctorado» y
 * «Especialidad» para llegar a lo suyo.
 *
 * Borrarlos no sirve: son los identificadores con los que se timbra un
 * certificado, así que el día que esa escuela abra un posgrado tiene que poder
 * volver a encenderlo con el MISMO id. Apagar conserva el número y limpia la
 * lista.
 *
 * ── Nacen encendidos ───────────────────────────────────────────────────────
 * `default(true)` y nada más: una migración que apagara lo que «parece» no
 * usarse cambiaría de un día para otro lo que las escuelas ya tienen a la vista.
 * Apagar es una decisión de cada escuela, y la toma en su pantalla.
 *
 * ── Los nombres, en mayúscula sólo la primera ──────────────────────────────
 * Venían del catálogo SEP en VERSALITAS —«SEMESTRE», «OBLIGATORIA»—, que es
 * como se publican ahí y no como se leen en una pantalla ni en un historial
 * impreso: el bloque del historial decía «SEMESTRE 1». Se normalizan a
 * «Semestre» y «Obligatoria».
 *
 * El nombre del TIPO DE ASIGNATURA es el único de los tres que viaja al web
 * service de la SEP (`tipoAsignatura` en el XML del certificado); ahí se sigue
 * mandando en mayúsculas, y de eso se encarga `ConstructorCertificadoXml`. El
 * nivel y el tipo de periodo viajan sólo por id, así que su nombre es
 * exclusivamente nuestro.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['niveles_estudio', 'tipos_periodo'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'activo')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->boolean('activo')->default(true)->after('nombre');
                });
            }
        }

        $this->normalizarNombres('tipos_periodo');
        $this->normalizarNombres('tipos_asignatura');
    }

    public function down(): void
    {
        foreach (['niveles_estudio', 'tipos_periodo'] as $tabla) {
            if (Schema::hasColumn($tabla, 'activo')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('activo');
                });
            }
        }

        // Los nombres NO se devuelven a versalitas: para entonces la escuela
        // pudo haber renombrado alguno a mano, y machacarlo con un
        // `strtoupper` perdería ese trabajo por revertir una migración de otra
        // cosa.
    }

    /**
     * «SEMESTRE» → «Semestre», respetando lo que ya esté bien escrito.
     *
     * Sólo se toca lo que viene TODO en mayúsculas: si alguien ya lo renombró a
     * «Cuatrimestre escolar», ese nombre es suyo y no hay por qué reescribirlo.
     */
    private function normalizarNombres(string $tabla): void
    {
        if (! Schema::hasTable($tabla)) {
            return;
        }

        foreach (DB::table($tabla)->get(['id', 'nombre']) as $fila) {
            $nombre = (string) $fila->nombre;

            if ($nombre === '' || $nombre !== mb_strtoupper($nombre)) {
                continue;
            }

            DB::table($tabla)->where('id', $fila->id)->update([
                'nombre' => mb_strtoupper(mb_substr($nombre, 0, 1)).mb_strtolower(mb_substr($nombre, 1)),
            ]);
        }
    }
};
