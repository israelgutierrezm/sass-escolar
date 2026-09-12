<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\Factura;
use App\Models\Identidad\Usuario;
use App\Services\Pagos\Pasarelas;

/**
 * El payload financiero de la app (autoservicio de factura y pago en línea),
 * en UN sitio: lo arman igual el portal del alumno y el de la familia. Escrito
 * dos veces, el día que cambie un campo un portal ofrecería facturar o pagar
 * algo que el otro ya no. El alcance —de quién es esta cuenta— lo cierra cada
 * controlador con `VeLaCarteraDelAlumno`; aquí sólo se ARMA lo que se muestra.
 */
class FinanzasParaApp
{
    public function __construct(
        private readonly AutoservicioFactura $autoservicio,
        private readonly Pasarelas $pasarelas,
        private readonly Ajustes $ajustes,
    ) {}

    /**
     * Lo de una matrícula: facturas emitidas, el autoservicio (sólo con el canal
     * abierto), las solicitudes y las cuentas para transferencia. La cuenta
     * (`EstadoCuenta::para`) la agrega cada controlador, porque el alumno la
     * pone arriba y la familia por matrícula.
     *
     * @return array<string, mixed>
     */
    public function facturaYCuentas(MatriculaOferta $m, bool $conAutoservicio): array
    {
        return [
            'facturas' => Factura::query()
                ->where('matricula_oferta_id', $m->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (Factura $f) => [
                    'uuid' => $f->uuid,
                    'total' => (float) $f->total,
                    'estatus' => $f->estatus,
                    'fecha' => $f->fecha_timbrado?->toDateString(),
                ])->values(),
            'factura_autoservicio' => $conAutoservicio ? $this->autoservicio->datosParaSolicitar($m) : null,
            'solicitudes_factura' => $this->autoservicio->solicitudesDe($m),
            'cuentas_bancarias' => CuentaBancaria::paraProgramaAcademico($m->oferta?->programa_academico_id)
                ->filter(fn (CuentaBancaria $c) => $c->puedeRecibir())
                ->map(fn (CuentaBancaria $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'banco' => $c->banco,
                    'titular' => $c->titular,
                    'clabe' => $c->clabe,
                    'numero_cuenta' => $c->numero_cuenta,
                    'instrucciones' => $c->instrucciones,
                ])->values()->all(),
        ];
    }

    /**
     * El modo del autoservicio de factura: 'generar' (emite al momento),
     * 'solicitar' (la escuela emite) o null. Depende del permiso de faceta y de
     * que la escuela haya abierto ese canal. Generar manda si están los dos.
     */
    public function facturaModo(Usuario $usuario): ?string
    {
        if ($usuario->can('generar-mi-factura') && $this->ajustes->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR)) {
            return 'generar';
        }

        if ($usuario->can('solicitar-factura') && $this->ajustes->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD)) {
            return 'solicitar';
        }

        return null;
    }

    /**
     * El bloque de pago en línea de la escuela: sus pasarelas, el abono mínimo y
     * si se permite pagar todo de una vez. Es de la escuela, no de la matrícula.
     *
     * @return array<string, mixed>
     */
    public function pago(): array
    {
        return [
            'pasarelas' => $this->pasarelas->disponibles(),
            'abono_minimo' => $this->ajustes->entero(CatalogoAjustes::ABONO_MINIMO),
            'pago_total' => $this->ajustes->bool(CatalogoAjustes::PAGO_TOTAL),
        ];
    }
}
