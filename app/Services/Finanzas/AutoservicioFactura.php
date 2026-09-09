<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\DatosFacturacion;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SolicitudFactura;
use App\Services\EmisorFactura;
use App\Support\CatalogosSat;

/**
 * Lo que el autoservicio de factura necesita mostrar, en UN sitio: el estado de
 * cuenta del alumno y el portal del padre lo piden igual, y tenerlo escrito dos
 * veces es como se llega a que un portal ofrezca facturar algo que el otro ya no.
 */
class AutoservicioFactura
{
    public function __construct(private readonly EmisorFactura $emisor) {}

    /**
     * Lo que necesita el panel de «Solicitar factura»: qué se puede facturar,
     * con qué datos (el perfil del alumno) y el catálogo del SAT.
     *
     * @return array<string, mixed>
     */
    public function datosParaSolicitar(MatriculaOferta $matricula): array
    {
        $perfil = DatosFacturacion::query()
            ->where('persona_id', $matricula->persona_id)
            ->whereNotNull('rfc')
            ->first();

        return [
            'pagos' => $this->emisor->facturables($matricula->id)
                ->map(fn (Pago $p) => [
                    'id' => $p->id,
                    'monto' => (float) $p->monto,
                    'metodo' => $p->metodoPago?->nombre,
                    'momento' => $p->momento?->toDateString(),
                    'concepto' => $p->adeudos->first()?->concepto?->nombre,
                ])->values(),
            'receptor' => $perfil === null ? null : [
                'rfc' => $perfil->rfc,
                'razon_social' => $perfil->razon_social,
                'uso_cfdi' => $perfil->uso_cfdi,
                'regimen_fiscal' => $perfil->regimen_fiscal,
                'cp' => $perfil->cp,
                'correo' => $perfil->correo_fiscal,
            ],
            'catalogos' => [
                'usos_cfdi' => CatalogosSat::usosCfdi(),
                'regimenes' => CatalogosSat::regimenesFiscales(),
            ],
        ];
    }

    /**
     * Las solicitudes de factura de esta matrícula, con su estado y el CFDI
     * cuando ya se emitió. Se enseñan aunque el canal esté apagado: el historial
     * no se esconde.
     *
     * @return array<int, array<string, mixed>>
     */
    public function solicitudesDe(MatriculaOferta $matricula): array
    {
        return SolicitudFactura::query()
            ->with('factura:id,uuid,estatus')
            ->where('matricula_oferta_id', $matricula->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (SolicitudFactura $s) => [
                'id' => $s->id,
                'estado' => $s->estado,
                'operaciones' => count($s->pago_ids ?? []),
                'receptor_rfc' => $s->receptor_rfc,
                'motivo_rechazo' => $s->motivo_rechazo,
                'factura' => $s->factura === null ? null : [
                    'uuid' => $s->factura->uuid,
                    'estatus' => $s->factura->estatus,
                    'descargable' => $s->factura->estatus === Factura::ESTATUS_TIMBRADA,
                ],
                'solicitada_en' => $s->created_at?->toDateString(),
            ])->values()->all();
    }
}
