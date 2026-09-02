<?php

declare(strict_types=1);

namespace App\Services\Banco;

use App\Exceptions\AvisoParaElUsuario;
use Carbon\CarbonImmutable;

/**
 * Convierte el CSV del banco en renglones con fecha, concepto y monto con
 * signo.
 *
 * ── Sólo CSV, y a propósito ────────────────────────────────────────────────
 * Todos los bancos lo ofrecen, y a diferencia del XLS no trae celdas
 * combinadas, encabezados a dos pisos ni renglones de totales al final que
 * haya que adivinar. Con XLS el mapeo de columnas no bastaría: harían falta
 * reglas por banco, que es justo lo que este diseño evita.
 *
 * ── La codificación NO es un detalle ───────────────────────────────────────
 * Los bancos mexicanos exportan en Windows-1252, no en UTF-8. Leído como UTF-8,
 * «Depósito» llega roto: el concepto se vuelve ilegible y —peor— la huella del
 * renglón cambia según cómo se haya guardado el archivo, así que reimportar el
 * mismo estado de cuenta duplicaría todo. Se detecta y se convierte.
 */
class LectorEstadoCuenta
{
    /**
     * @return array<int, array{fecha: string, descripcion: string, referencia: ?string, monto: float}>
     */
    public function leer(string $rutaAbsoluta, MapeoEstadoCuenta $mapeo): array
    {
        AvisoParaElUsuario::aMenosQue(
            is_readable($rutaAbsoluta),
            422,
            'No se pudo leer el archivo.',
        );

        $manejador = fopen($rutaAbsoluta, 'r');

        AvisoParaElUsuario::aMenosQue($manejador !== false, 422, 'No se pudo abrir el archivo.');

        $encabezado = null;
        $renglon = 0;
        $salida = [];

        try {
            while (($celdas = fgetcsv($manejador, 0, $mapeo->delimitador(), '"', '\\')) !== false) {
                $renglon++;

                if ($celdas === [null] || $celdas === false) {
                    continue;   // renglón en blanco
                }

                $celdas = array_map(fn ($c) => $this->aUtf8((string) ($c ?? '')), $celdas);

                if ($renglon < $mapeo->renglonEncabezado()) {
                    continue;   // preámbulo con los datos de la cuenta
                }

                if ($encabezado === null) {
                    $encabezado = $this->indexar($celdas, $mapeo);

                    continue;
                }

                $fila = $this->interpretar($celdas, $encabezado, $mapeo, $renglon);

                if ($fila !== null) {
                    $salida[] = $fila;
                }
            }
        } finally {
            fclose($manejador);
        }

        AvisoParaElUsuario::aMenosQue(
            $salida !== [],
            422,
            'El archivo no trajo ningún movimiento. Revisa el renglón del encabezado y el separador del mapeo.',
        );

        return $salida;
    }

    /**
     * Dónde está cada columna que el mapeo nombra.
     *
     * Se compara sin acentos ni mayúsculas: la misma columna sale como
     * «Descripción», «DESCRIPCION» y «Descripcion» según el banco y la versión
     * del archivo, y obligar a la escuela a acertar el acento exacto convierte
     * un mapeo bueno en un error incomprensible.
     *
     * @param  array<int, string>  $celdas
     * @return array<string, int>
     */
    private function indexar(array $celdas, MapeoEstadoCuenta $mapeo): array
    {
        $normalizados = [];

        foreach ($celdas as $i => $titulo) {
            $normalizados[$this->normalizar($titulo)] = $i;
        }

        $indice = [];

        $pedidas = array_filter([
            'fecha' => $mapeo->columnaFecha(),
            'descripcion' => $mapeo->columnaDescripcion(),
            'referencia' => $mapeo->columnaReferencia(),
            'monto' => $mapeo->columnaMonto(),
            'cargo' => $mapeo->columnaCargo(),
            'abono' => $mapeo->columnaAbono(),
        ]);

        foreach ($pedidas as $papel => $titulo) {
            $clave = $this->normalizar($titulo);

            // La referencia puede no venir en el archivo y no es un error: hay
            // bancos que la meten dentro del concepto. Lo demás sí.
            if (! array_key_exists($clave, $normalizados)) {
                AvisoParaElUsuario::si(
                    $papel !== 'referencia',
                    422,
                    "El archivo no tiene una columna «{$titulo}». Las que trae son: ".implode(', ', $celdas),
                );

                continue;
            }

            $indice[$papel] = $normalizados[$clave];
        }

        return $indice;
    }

    /**
     * @param  array<int, string>  $celdas
     * @param  array<string, int>  $indice
     * @return array{fecha: string, descripcion: string, referencia: ?string, monto: float}|null
     */
    private function interpretar(array $celdas, array $indice, MapeoEstadoCuenta $mapeo, int $renglon): ?array
    {
        $crudaFecha = trim($celdas[$indice['fecha']] ?? '');
        $descripcion = trim($celdas[$indice['descripcion']] ?? '');

        /*
         * Un renglón sin fecha NO es un error: los estados de cuenta terminan
         * con líneas de totales y de leyendas legales. Reventar ahí haría que
         * ningún archivo real se pudiera importar.
         */
        if ($crudaFecha === '') {
            return null;
        }

        $fecha = $this->fecha($crudaFecha, $mapeo->formatoFecha(), $renglon);
        $monto = $this->monto($celdas, $indice, $renglon);

        // Un movimiento de cero no existe en un estado de cuenta; es un
        // renglón de encabezado de sección o un total que trajo fecha.
        if (abs($monto) < 0.005) {
            return null;
        }

        return [
            'fecha' => $fecha,
            'descripcion' => $descripcion !== '' ? mb_substr($descripcion, 0, 255) : 'Sin concepto',
            'referencia' => isset($indice['referencia'])
                ? (trim($celdas[$indice['referencia']] ?? '') ?: null)
                : null,
            'monto' => $monto,
        ];
    }

    /**
     * @param  array<int, string>  $celdas
     * @param  array<string, int>  $indice
     */
    private function monto(array $celdas, array $indice, int $renglon): float
    {
        if (isset($indice['monto'])) {
            return $this->aNumero($celdas[$indice['monto']] ?? '', $renglon);
        }

        // Cargo y abono en columnas separadas: el cargo SALE, así que va en
        // negativo. Un renglón trae uno de los dos, nunca los dos.
        $cargo = isset($indice['cargo']) ? abs($this->aNumero($celdas[$indice['cargo']] ?? '', $renglon)) : 0.0;
        $abono = isset($indice['abono']) ? abs($this->aNumero($celdas[$indice['abono']] ?? '', $renglon)) : 0.0;

        return round($abono - $cargo, 2);
    }

    /**
     * «$1,234.56», «1234.56», «(1,234.56)» y «-1,234.56» son el mismo número.
     *
     * El paréntesis es notación contable de negativo y aparece en varios
     * bancos: sin tratarlo, un cargo de 1,234.56 se importaría como positivo y
     * el saldo no cuadraría por el doble de su importe.
     */
    private function aNumero(string $crudo, int $renglon): float
    {
        $t = trim($crudo);

        if ($t === '' || $t === '-') {
            return 0.0;
        }

        $negativo = str_starts_with($t, '(') && str_ends_with($t, ')');
        $limpio = preg_replace('/[^0-9.\-]/', '', $t) ?? '';

        AvisoParaElUsuario::si(
            $limpio === '' || ! is_numeric($limpio),
            422,
            "El renglón {$renglon} trae «{$crudo}» donde va un importe.",
        );

        $valor = (float) $limpio;

        return round($negativo ? -abs($valor) : $valor, 2);
    }

    private function fecha(string $crudo, string $formato, int $renglon): string
    {
        $fecha = CarbonImmutable::createFromFormat($formato, $crudo);

        AvisoParaElUsuario::aMenosQue(
            $fecha !== false,
            422,
            "El renglón {$renglon} trae la fecha «{$crudo}», que no encaja con el formato «{$formato}» del mapeo.",
        );

        return $fecha->toDateString();
    }

    /** Windows-1252 → UTF-8. Lo ya válido se deja tal cual. */
    private function aUtf8(string $texto): string
    {
        if ($texto === '' || mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }

    private function normalizar(string $texto): string
    {
        $sinAcentos = strtr(
            mb_strtolower(trim($texto)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'],
        );

        // El BOM del archivo se pega al primer encabezado y lo vuelve
        // irreconocible sin que se vea nada raro en pantalla.
        return trim(str_replace("\u{FEFF}", '', $sinAcentos));
    }
}
