<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Factura;
use App\Reportes\Salida\TextoDeCelda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Arma el ZIP con los comprobantes de un periodo.
 *
 * ── Para qué ───────────────────────────────────────────────────────────────
 * Es el trabajo mensual del contador: llevarse los XML del mes para la
 * contabilidad electrónica. Sin esto se bajan uno por uno desde la pantalla de
 * cada factura, que con doscientas al mes no lo hace nadie — y lo que de verdad
 * pasa es que se piden por correo al PAC.
 *
 * ── Las CANCELADAS van dentro ──────────────────────────────────────────────
 * Tentador dejarlas fuera («ya no valen»), y sería un error: la contabilidad
 * electrónica las pide igual, porque un CFDI cancelado también hay que poder
 * justificarlo. Lo que sí hace falta es que el paquete diga cuáles lo están, y
 * de eso se encarga el manifiesto.
 *
 * ── El MANIFIESTO es la mitad del entregable ───────────────────────────────
 * Un ZIP con doscientos archivos no se puede cuadrar contra nada: no dice
 * cuántos debería haber, ni cuáles faltan, ni cuáles están cancelados. El CSV
 * lista TODO lo del periodo —incluido lo que no tiene XML guardado— con su
 * estado, así que la ausencia de un archivo deja de ser invisible.
 *
 * Sin él, un paquete al que le faltan tres XML porque la descarga desde el PAC
 * falló se ve exactamente igual que uno completo.
 */
class DescargaMasivaCfdi
{
    /**
     * Tope de comprobantes por descarga.
     *
     * Se REHÚSA en vez de recortar. Un ZIP truncado en silencio es peor que no
     * tenerlo: se entrega a contabilidad como si fuera el mes completo. Quien
     * necesite más parte el rango, que es una decisión suya y no nuestra.
     */
    public const TOPE = 2000;

    /**
     * El tope entra por el constructor para que se pueda comprobar.
     *
     * Con la constante a secas, la unica forma de ejercitar el rechazo seria
     * crear dos mil comprobantes en una prueba: nadie lo hace, asi que esa regla
     * se quedaria sin comprobar, que es como se llega a descubrir en produccion
     * que el tope estaba mal escrito.
     */
    public function __construct(private readonly int $tope = self::TOPE) {}

    /**
     * @param  Builder<Factura>  $consulta  ya acotada por campus
     * @return array{ruta: string, nombre: string, comprobantes: int, sinArchivo: int}
     */
    public function armar(Builder $consulta, string $desde, string $hasta, bool $conPdf = false): array
    {
        $facturas = (clone $consulta)
            ->whereNotNull('uuid')
            ->whereBetween('fecha_timbrado', [$desde.' 00:00:00', $hasta.' 23:59:59'])
            ->orderBy('fecha_timbrado')
            ->get();

        if ($facturas->isEmpty()) {
            throw new RuntimeException('No hay comprobantes timbrados en ese periodo.');
        }

        if ($facturas->count() > $this->tope) {
            throw new RuntimeException(
                'Son '.$facturas->count().' comprobantes y el tope por descarga es '.$this->tope
                .'. Parte el periodo en tramos más cortos: un paquete recortado a la mitad se entrega '
                .'a contabilidad como si fuera el mes completo.'
            );
        }

        // Se escribe en la ruta que devuelve `tempnam` y no en «esa ruta + .zip»:
        // `tempnam` CREA el archivo, así que concatenarle una extensión deja el
        // original huérfano en el temporal para siempre, uno por descarga.
        $ruta = tempnam(sys_get_temp_dir(), 'cfdi');

        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del paquete.');
        }

        try {
            $sinArchivo = $this->escribir($ruta, $facturas, $conPdf);
        } catch (Throwable $e) {
            // El temporal se borra también cuando el armado falla: sin esto,
            // cada intento fallido deja el archivo en la partición. Es la
            // lección que dejó el archivado de grabaciones.
            @unlink($ruta);

            throw $e;
        }

        return [
            'ruta' => $ruta,
            'nombre' => 'cfdi-'.$desde.'-a-'.$hasta.'.zip',
            'comprobantes' => $facturas->count(),
            'sinArchivo' => $sinArchivo,
        ];
    }

    /**
     * @param  Collection<int, Factura>  $facturas
     * @return int cuántas no tenían XML guardado
     */
    private function escribir(string $ruta, $facturas, bool $conPdf): int
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo abrir el paquete para escribirlo.');
        }

        $disco = Storage::disk('local');
        $sinArchivo = 0;
        $renglones = [];

        foreach ($facturas as $factura) {
            // El nombre empieza por la FECHA para que el paquete se lea en
            // orden al abrirlo, y lleva el folio fiscal porque es lo único que
            // identifica un CFDI sin lugar a dudas.
            $base = $factura->fecha_timbrado?->format('Y-m-d').'_'.$factura->uuid;

            $tieneXml = filled($factura->xml_ruta) && $disco->exists($factura->xml_ruta);

            if ($tieneXml) {
                $zip->addFromString("{$base}.xml", (string) $disco->get($factura->xml_ruta));
            } else {
                $sinArchivo++;
            }

            if ($conPdf && filled($factura->pdf_ruta) && $disco->exists($factura->pdf_ruta)) {
                $zip->addFromString("{$base}.pdf", (string) $disco->get($factura->pdf_ruta));
            }

            $renglones[] = [
                $factura->uuid,
                $factura->fecha_timbrado?->toDateTimeString(),
                $factura->tipo === Factura::TIPO_EGRESO ? 'Egreso (nota de crédito)' : 'Ingreso',
                $factura->receptor_rfc,
                $factura->receptor_razon_social,
                number_format((float) $factura->subtotal, 2, '.', ''),
                number_format((float) $factura->iva, 2, '.', ''),
                number_format((float) $factura->total, 2, '.', ''),
                $factura->estatus,
                $factura->sat_estado ?? 'sin conciliar',
                $tieneXml ? 'sí' : 'NO — el XML no está guardado',
            ];
        }

        $zip->addFromString('manifiesto.csv', $this->csv($renglones));
        $zip->close();

        return $sinArchivo;
    }

    /**
     * @param  array<int, array<int, string|null>>  $renglones
     */
    private function csv(array $renglones): string
    {
        $cabecera = [
            'Folio fiscal', 'Fecha', 'Tipo', 'RFC receptor', 'Receptor',
            'Subtotal', 'IVA', 'Total', 'Estatus aquí', 'Estado en el SAT', 'XML en el paquete',
        ];

        $lineas = [$this->linea($cabecera)];

        foreach ($renglones as $renglon) {
            $lineas[] = $this->linea($renglon);
        }

        // BOM: sin él, Excel abre el CSV en su codificación local y las razones
        // sociales con acentos salen rotas. Es el archivo que va a contabilidad.
        return "\xEF\xBB\xBF".implode("\r\n", $lineas)."\r\n";
    }

    /**
     * @param  array<int, string|null>  $celdas
     */
    private function linea(array $celdas): string
    {
        return implode(',', array_map(function (?string $celda) {
            // Lo que sale del sistema hay que neutralizarlo: Excel toma como
            // fórmula lo que empieza por `= + - @`, y la razón social la
            // escribió alguien de fuera.
            $texto = TextoDeCelda::neutralizado((string) $celda);

            return '"'.str_replace('"', '""', $texto).'"';
        }, $celdas));
    }
}
