<?php

declare(strict_types=1);

namespace App\Documentos;

use App\Models\Academico\Institucion;
use App\Models\Finanzas\Pago;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * El comprobante que se le entrega a quien paga en ventanilla.
 *
 * ── NO es un CFDI, y el papel lo dice ──────────────────────────────────────
 * Es la regla que gobierna este documento. Un recibo con el logo de la escuela,
 * un folio y un importe se parece lo bastante a una factura como para que
 * alguien lo archive creyendo que puede deducirlo, y se entere en abril. El
 * recibo lo dice con todas sus letras y remite a la factura, que se pide
 * aparte.
 *
 * ── El folio es el id del pago ─────────────────────────────────────────────
 * Identifica exactamente un cobro y no hay dos. Un consecutivo por caja exigiría
 * otro contador atómico —como `contadores_acta`— y no aporta nada mientras
 * nadie tenga que citarlo ante un tercero: para el SAT vale el CFDI, no esto.
 *
 * ── En PDF y no en Blade ───────────────────────────────────────────────────
 * Se entrega, se guarda y a veces se manda por correo, así que tiene que verse
 * igual en cualquier parte. Es el mismo criterio que llevó el historial a PDF;
 * lo que sigue en Blade es la copia de ventanilla del acta, que se firma a mano.
 */
class ReciboDeCaja
{
    public function __construct(private readonly DocumentoPdf $pdf) {}

    public function responder(Pago $pago): Response
    {
        return new Response($this->generar($pago), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="recibo-'.$pago->id.'.pdf"',
        ]);
    }

    public function generar(Pago $pago): string
    {
        $pago->loadMissing([
            'metodoPago',
            'sesionCaja.caja.campus',
            'sesionCaja.usuario.persona',
            'adeudos.concepto',
            'matriculaOferta.persona',
            'matriculaOferta.oferta.programaAcademico',
            'aspirante.persona',
        ]);

        $institucion = Institucion::query()->first();

        return $this->pdf->generar(
            View::make('documentos.recibo-caja', [
                'pago' => $pago,
                'institucion' => $institucion,
                // El titular puede ser una matrícula O un aspirante: es el
                // mismo cobro con distinto dueño, y el recibo no tiene por qué
                // saber cuál para decir a nombre de quién va.
                'titular' => $pago->matriculaOferta?->persona ?? $pago->aspirante?->persona,
                'matricula' => $pago->matriculaOferta?->matricula,
                'programa' => $pago->matriculaOferta?->oferta?->programaAcademico?->nombre,
            ])->render(),
            [
                // Media carta: es un recibo de mostrador, y una hoja entera para
                // seis renglones se tira a la basura en cuanto sale de la
                // ventanilla.
                'papel' => 'a5',
                'orientacion' => 'vertical',
                'margen_superior' => 12,
                'margen_inferior' => 12,
            ],
        );
    }
}
