<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Models\Reportes\ReporteEscuela;

/**
 * ¿Este reporte de tabla todavía casa con su fuente?
 *
 * ── Escrito UNA vez porque lo preguntan DOS caminos ────────────────────────
 * El constructor al GUARDAR —para no dejar guardar algo que no va a correr— y
 * el registro al CARGAR —porque la fuente pudo cambiar después—. Con la regla
 * escrita en los dos sitios, la pantalla acabaría prometiendo una cosa y el
 * motor haciendo otra; y aquí eso se paga con un reporte que sale con otro
 * alcance y que alguien lleva a una junta.
 *
 * ── Y por qué son FATALES casi todas ──────────────────────────────────────
 * El motor es tolerante a propósito con las vistas guardadas: una columna
 * retirada se descarta en silencio para que la vista de hace un año siga
 * abriendo. Eso vale para una columna —enseña de menos— y NO vale para un
 * filtro: un filtro fijo que desaparece deja el reporte contestando una
 * pregunta MÁS ANCHA con el mismo nombre. «Bajas del campus norte» pasaría a
 * decir «bajas» y nadie lo notaría, porque no falla.
 *
 * Un reporte con un problema no se sirve: se retira del catálogo con su razón
 * escrita, que es lo que hace que alguien lo pueda arreglar.
 */
final class RevisionDelReporte
{
    /**
     * El problema que impide servirlo, o null si está bien.
     *
     * La fuente llega resuelta —o en null si ya no existe— para que esta clase
     * no dependa del registro: si dependiera, el registro no podría usarla al
     * poblarse.
     */
    public static function problema(ReporteEscuela $fila, ?FuenteDeReporte $fuente): ?string
    {
        if ($fuente === null) {
            return "Su fuente «{$fila->fuente}» ya no existe en el sistema.";
        }

        $columnas = $fuente->columnas();
        $filtros = $fuente->filtros();

        $vivas = array_filter(
            $fila->columnas ?? [],
            fn ($c) => is_string($c) && isset($columnas[$c]),
        );

        /*
         * Una columna retirada se descarta, como en una vista guardada: enseña
         * de menos y eso no engaña a nadie. Que no quede NINGUNA sí es fatal —
         * un reporte sin columnas no es un reporte, y el motor caería a la
         * primera del catálogo, que no es la que nadie eligió.
         */
        if ($vivas === []) {
            return 'Ninguna de sus columnas existe ya en la fuente.';
        }

        foreach ($fila->filtros_fijos ?? [] as $clave => $valor) {
            if (! isset($filtros[$clave])) {
                return "Su filtro fijo «{$clave}» ya no existe en la fuente, así que "
                    .'el reporte contestaría una pregunta más ancha con el mismo nombre.';
            }
        }

        foreach ($fila->filtros_obligatorios ?? [] as $clave) {
            if (! is_string($clave) || ! isset($filtros[$clave])) {
                return "Pide como obligatorio el filtro «{$clave}», que ya no existe en la fuente.";
            }

            /*
             * Fijo y obligatorio a la vez es una trampa de pantalla: el fijo no
             * se puede elegir —no se dibuja siquiera—, así que el motor pediría
             * elegir algo que quien lo corre no puede tocar.
             */
            if (array_key_exists($clave, $fila->filtros_fijos ?? [])) {
                return "El filtro «{$clave}» está fijo Y pedido como obligatorio: "
                    .'quien corra el reporte no puede elegirlo, así que nunca podría correrlo.';
            }
        }

        if ($fila->orden_por !== null && $fila->orden_por !== '') {
            $columna = $columnas[$fila->orden_por] ?? null;

            /*
             * Misma regla que `RegistroReportes::registrarReporte` le aplica a
             * un reporte del código, con la única diferencia de que aquí no
             * puede tumbar la pantalla entera: el motor descarta un orden que no
             * puede aplicar SIN AVISAR y sale ordenado por la llave primaria
             * mientras la definición declara otra cosa.
             */
            if ($columna === null || ! $columna->ordenable) {
                return "Lo ordena por «{$fila->orden_por}», que "
                    .($columna === null ? 'ya no existe en la fuente.' : 'no se puede ordenar.');
            }
        }

        return null;
    }
}
