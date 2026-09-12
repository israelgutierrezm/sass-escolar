<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\SolicitudFactura;
use App\Support\CatalogosSat;
use App\Support\PasarelasCatalogo;
use App\Support\UrlPublica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Solicitar/generar factura, pagar en línea y subir el comprobante, para la API
 * de la app. En UN sitio porque lo hacen igual el portal del ALUMNO y el de la
 * FAMILIA —como la web, que usa un solo controlador para los dos—: la validación
 * del receptor, las guardas del canal y el manejo del error de la pasarela no
 * pueden decir una cosa en un portal y otra en el otro.
 *
 * El controlador que lo usa debe traer también `VeLaCarteraDelAlumno` (de ahí
 * sale `exigirQuePuedaVerLaCuenta`, que cierra de quién es la cuenta según la
 * faceta) e inyectar los servicios compartidos como propiedades:
 * `$gestorFactura` (GestorSolicitudFactura), `$cobro` (CobroEnLinea) y
 * `$registroComprobante` (RegistroDeComprobante).
 */
trait OperaFinanzasEnLinea
{
    /**
     * SOLICITA factura de unos pagos (la escuela la emite). El canal cerrado por
     * la escuela responde 404 (no 403). La regla de negocio y las guardas viven
     * en `GestorSolicitudFactura`.
     */
    public function solicitarFactura(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        AvisoParaElUsuario::aMenosQue(
            app(Ajustes::class)->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD),
            404,
            'La solicitud de factura en línea no está disponible en esta escuela.',
        );

        $datos = $this->datosDelReceptor($peticion);
        $this->gestorFactura->solicitar($matricula, $datos['pago_ids'], $datos['receptor'], $peticion->user());

        return response()->json(['ok' => true]);
    }

    /**
     * GENERA la factura al momento: nace el CFDI sin pasar por la bandeja.
     * Capacidad y canal APARTE de solicitar (emitir a nombre de la escuela es más
     * delicado). Mismo motor, mismas guardas.
     */
    public function generarFactura(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        AvisoParaElUsuario::aMenosQue(
            app(Ajustes::class)->bool(CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR),
            404,
            'Generar tu factura en línea no está disponible en esta escuela.',
        );

        $datos = $this->datosDelReceptor($peticion);
        $this->gestorFactura->generarDirecto($matricula, $datos['pago_ids'], $datos['receptor'], $peticion->user());

        return response()->json(['ok' => true]);
    }

    /**
     * El CFDI de una solicitud ya emitida, para quien puede ver esa cuenta. Sólo
     * lo TIMBRADO —lo que la lista marca `descargable`—; lo demás → 404.
     */
    public function descargarCfdi(Request $peticion, SolicitudFactura $solicitud, string $tipo): StreamedResponse
    {
        abort_unless(in_array($tipo, ['xml', 'pdf'], true), 404);

        $matricula = $solicitud->matriculaOferta;
        abort_unless($matricula !== null, 404);

        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        $factura = $solicitud->factura;
        abort_if($factura === null || ! $factura->estaVigente(), 404);

        $ruta = $tipo === 'xml' ? $factura->xml_ruta : $factura->pdf_ruta;
        abort_if($ruta === null || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->download($ruta, ($factura->uuid ?? 'factura-'.$factura->id).'.'.$tipo);
    }

    /**
     * Empieza un cobro en línea y devuelve a dónde mandar a quien paga.
     *
     * Reusa `CobroEnLinea::iniciar` con las mismas dos URLs: el RETORNO lo abre el
     * navegador de vuelta; el AVISO lo abre la pasarela desde internet (el
     * webhook, que es lo único que cobra). La app abre la URL devuelta y luego
     * relee el estado de cuenta: el webhook concilia aunque la app se cierre.
     */
    public function iniciarPago(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        $datos = $peticion->validate([
            'pasarela' => ['required', 'string', 'max:30'],
            'adeudo_ids' => ['required', 'array', 'min:1'],
            'adeudo_ids.*' => ['integer'],
            'metodo' => ['nullable', 'string', 'max:20'],
            'importe' => ['nullable', 'numeric', 'min:0.01'],
        ], [
            'adeudo_ids.required' => 'Elige al menos un cargo para pagar.',
        ]);

        try {
            $intencion = $this->cobro->iniciar(
                $matricula,
                $datos['pasarela'],
                $datos['adeudo_ids'],
                route('tenant.pagos.retorno'),
                UrlPublica::paraAfuera(route('tenant.pagos.aviso', ['pasarela' => $datos['pasarela']])),
                $datos['metodo'] ?? null,
                isset($datos['importe']) ? (float) $datos['importe'] : null,
            );
        } catch (HttpException $e) {
            // Un aviso para quien paga (falta elegir método, cargos, «pagar
            // todo»…) ya trae su mensaje y su código; el manejador de /api lo da
            // como JSON. Sin esta rama caería abajo y se culparía a la pasarela.
            throw $e;
        } catch (RuntimeException $e) {
            // Falló algo de la escuela (credenciales, pasarela mal configurada):
            // al registro el motivo, a quien paga que no es culpa suya.
            Log::error('No se pudo abrir un cobro en línea desde la app.', [
                'pasarela' => $datos['pasarela'],
                'matricula' => $matricula->id,
                'motivo' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo abrir el pago con '.PasarelasCatalogo::nombreDe($datos['pasarela'])
                    .'. No es problema tuyo: avísale a la escuela para que lo revise.',
            ], 422);
        }

        return response()->json(['url' => $intencion->url_pago]);
    }

    /**
     * Sube el comprobante de una transferencia ya hecha (multipart). Nace
     * PENDIENTE; la escuela lo valida y ahí se liquida el cargo. Mismo servicio
     * que la web (`RegistroDeComprobante`), que filtra los cargos al titular.
     */
    public function subirComprobante(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($peticion, $matricula);

        $datos = $peticion->validate([
            'cuenta_bancaria_id' => ['nullable', 'integer'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha_transferencia' => ['required', 'date', 'before_or_equal:today'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'adeudo_ids' => ['nullable', 'array'],
            'adeudo_ids.*' => ['integer'],
            'archivo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'fecha_transferencia.before_or_equal' => 'La fecha de la transferencia no puede ser futura.',
            'archivo.mimes' => 'El comprobante tiene que ser una imagen o un PDF.',
            'archivo.max' => 'El comprobante no puede pesar más de 5 MB.',
        ]);

        $this->registroComprobante->registrar($matricula, $peticion->file('archivo'), $datos);

        return response()->json(['ok' => true]);
    }

    /**
     * Valida y arma el receptor del autoservicio, contra el catálogo del SAT: lo
     * que se pide tiene que poder timbrarse tal cual. El correo es de ENTREGA.
     *
     * @return array{pago_ids: array<int, int>, receptor: array<string, string|null>}
     */
    private function datosDelReceptor(Request $peticion): array
    {
        $datos = $peticion->validate([
            'pago_ids' => ['required', 'array', 'min:1'],
            'pago_ids.*' => ['integer'],
            'rfc' => ['required', 'string', 'min:12', 'max:13'],
            'razon_social' => ['required', 'string', 'max:255'],
            'uso_cfdi' => ['required', 'string', Rule::in(CatalogosSat::clavesUsosCfdi())],
            'regimen_fiscal' => ['required', 'string', Rule::in(CatalogosSat::clavesRegimenes())],
            'cp' => ['required', 'string', 'size:5'],
            'correo' => ['nullable', 'email', 'max:190'],
        ]);

        return [
            'pago_ids' => $datos['pago_ids'],
            'receptor' => [
                'rfc' => $datos['rfc'],
                'razon_social' => $datos['razon_social'],
                'uso_cfdi' => $datos['uso_cfdi'],
                'regimen_fiscal' => $datos['regimen_fiscal'],
                'cp' => $datos['cp'],
                'correo' => $datos['correo'] ?? null,
            ],
        ];
    }
}
