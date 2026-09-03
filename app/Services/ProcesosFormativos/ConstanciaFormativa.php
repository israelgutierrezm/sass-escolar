<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Documentos\DocumentoPdf;
use App\Models\Academico\Institucion;
use App\Models\ProcesosFormativos\LiberacionProceso;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * La constancia de liberación, en PDF.
 *
 * ── TODO sale del SNAPSHOT ────────────────────────────────────────────────
 * Ni una sola relación viva. Una constancia reimpresa dentro de tres años tiene
 * que decir exactamente lo que decía la primera: la organización pudo cambiar
 * de razón social, la regla de horas exigidas y el alumno de programa, y
 * releerlo hoy haría que el mismo folio ampare dos textos distintos. Es el
 * criterio del emisor congelado en la factura y de `factura_iedu`.
 *
 * ── Y la CORREGIDA lleva marca de agua ────────────────────────────────────
 * Una liberación jubilada se puede seguir reimprimiendo —hay que poder ver qué
 * decía el papel que circuló—, pero no puede salir con el mismo aspecto que la
 * vigente. La marca la pone el motor debajo del contenido de todas las hojas y
 * no se quita borrando un nodo, que es justo el punto. Mismo criterio que el
 * historial sin firmar.
 */
class ConstanciaFormativa
{
    public function __construct(private readonly DocumentoPdf $pdf) {}

    public function responder(LiberacionProceso $liberacion): Response
    {
        return new Response($this->generar($liberacion), 200, [
            'Content-Type' => 'application/pdf',
            /*
             * `inline`: quien emite una constancia la MIRA antes de imprimirla,
             * y el visor del navegador ya trae el botón de guardar.
             */
            'Content-Disposition' => 'inline; filename="'.$this->nombreDeArchivo($liberacion).'"',
        ]);
    }

    public function generar(LiberacionProceso $liberacion): string
    {
        $institucion = Institucion::query()->first();

        $html = View::make('impresion.constancia-formativa', [
            'liberacion' => $liberacion,
            'datos' => $liberacion->snapshot,
            'institucion' => $institucion,
        ])->render();

        return $this->pdf->generar($html, [
            'papel' => 'carta',
            'orientacion' => 'vertical',
            'titulo' => 'Constancia '.$liberacion->folio,
            'margen_superior' => 18,
            'margen_inferior' => 18,
            'pie' => $this->pie($liberacion),
            /*
             * La marca de agua sólo en la CORREGIDA. Ponerla siempre entrenaría
             * a ignorarla, que es lo contrario de lo que sirve.
             */
            'marca_agua' => $liberacion->estaVigente() ? null : 'SIN EFECTO',
            'marca_agua_opacidad' => 12,
        ]);
    }

    public function nombreDeArchivo(LiberacionProceso $liberacion): string
    {
        return 'constancia-'.str_replace(['/', '\\', ' '], '-', $liberacion->folio).'.pdf';
    }

    /**
     * El pie: folio y hoja, en TODAS las hojas.
     *
     * Sin el folio repetido, una hoja suelta de una constancia no se puede
     * devolver a su documento — y sin «hoja N de M» no se sabe si falta alguna.
     * Es la lección del historial impreso.
     */
    private function pie(LiberacionProceso $liberacion): string
    {
        $aviso = $liberacion->estaVigente()
            ? ''
            : ' · <strong>Este folio quedó sin efecto: pide el vigente.</strong>';

        return '<div style="font-size:8pt;color:#555;border-top:1px solid #ccc;padding-top:4px;">'
            .'Folio '.e($liberacion->folio).$aviso
            .' <span style="float:right">Hoja {PAGENO} de {nbpg}</span></div>';
    }
}
