<?php

declare(strict_types=1);

use App\Historial\CatalogoColumnas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada columna del historial guarda su ANCHO y su ALINEACIÓN.
 *
 * ── El hueco ─────────────────────────────────────────────────────────────
 * `disenos_historial.columnas` era una lista de claves: `["clave","materia"]`.
 * El ancho y la alineación vivían cableados en `CatalogoColumnas`, iguales para
 * todas las escuelas. Y es lo que MÁS se ajusta en un historial real: una
 * escuela con nombres de asignatura largos necesita darle más sitio a
 * «Asignatura», y otra que imprime la observación de la SEP necesita al revés.
 *
 * ── El formato nuevo ─────────────────────────────────────────────────────
 * `[{"clave":"materia","ancho":38,"alineacion":"izquierda"}, …]`. No es una
 * tabla porque no hay nada que colgarle a una columna —ni archivos, ni
 * historia— y porque el ORDEN es parte del dato: en una tabla habría que
 * mantener un `orden` que aquí es la posición del arreglo.
 *
 * ── La conversión ────────────────────────────────────────────────────────
 * Lo guardado se convierte usando los anchos que hasta hoy estaban cableados,
 * así que ninguna escuela ve cambiar su documento por esta migración. Y
 * `columnasEfectivas()` sigue aceptando la forma vieja: un diseño puede quedar
 * guardado con el formato anterior por una petición en vuelo durante el
 * despliegue, y eso no puede dejar el historial sin columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('disenos_historial')) {
            return;
        }

        $catalogo = CatalogoColumnas::columnas();

        foreach (DB::table('disenos_historial')->get(['id', 'columnas']) as $diseno) {
            $columnas = json_decode((string) $diseno->columnas, true);

            if (! is_array($columnas) || $columnas === []) {
                continue;
            }

            // Ya convertido: un reintento tras un fallo parcial no debe volver a
            // pasarle por encima.
            if (is_array($columnas[0] ?? null)) {
                continue;
            }

            $nuevas = [];

            foreach ($columnas as $clave) {
                if (! is_string($clave) || ! isset($catalogo[$clave])) {
                    continue;
                }

                $nuevas[] = [
                    'clave' => $clave,
                    'ancho' => $catalogo[$clave]['ancho'],
                    'alineacion' => $catalogo[$clave]['alineacion'],
                ];
            }

            DB::table('disenos_historial')
                ->where('id', $diseno->id)
                ->update(['columnas' => json_encode($nuevas)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('disenos_historial')) {
            return;
        }

        foreach (DB::table('disenos_historial')->get(['id', 'columnas']) as $diseno) {
            $columnas = json_decode((string) $diseno->columnas, true);

            if (! is_array($columnas) || ! is_array($columnas[0] ?? null)) {
                continue;
            }

            // Se vuelve a la lista de claves; el ancho y la alineación que la
            // escuela hubiera ajustado se pierden, que es lo que significa
            // volver a tenerlos cableados.
            DB::table('disenos_historial')
                ->where('id', $diseno->id)
                ->update(['columnas' => json_encode(array_column($columnas, 'clave'))]);
        }
    }
};
