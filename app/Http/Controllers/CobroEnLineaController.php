<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\VeLaCarteraDelAlumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use App\Services\Pagos\CobroEnLinea;
use App\Services\Pagos\EstadoCobro;
use App\Services\Pagos\Pasarelas;
use App\Services\Pagos\ResultadoCobro;
use App\Support\PasarelasCatalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Pagar en línea: los cuatro saltos del cobro.
 *
 * `iniciar` manda a la pasarela · `retorno` recibe al navegador de vuelta ·
 * `aviso` recibe el webhook, que es lo único que decide · `simulador` existe
 * sólo en modo de pruebas.
 *
 * ── Quién puede pagar qué ──────────────────────────────────────────────────
 * El mismo criterio que ya cierra el estado de cuenta, y por la misma razón por
 * la que se escribió así: tenerlo en dos sitios fue exactamente lo que se
 * descompuso antes —al padre de familia se le mostraba la cartera entera y
 * luego se le cerraba la cuenta de su propio hijo—. Aquí se delega en
 * `FinanzasController::puedeVer`, que es el único que sabe la regla.
 */
class CobroEnLineaController extends Controller
{
    use AcotaPorCampus;
    use VeLaCarteraDelAlumno;

    public function __construct(
        private readonly CobroEnLinea $cobro,
        private readonly Pasarelas $pasarelas,
    ) {}

    /**
     * Empieza el cobro y manda a la pasarela.
     *
     * Devuelve la URL en vez de redirigir desde el servidor porque la petición
     * viene de Inertia: un `redirect()` a un dominio ajeno acabaría intentando
     * renderizarse como página de la aplicación.
     */
    public function iniciar(Request $request, MatriculaOferta $matricula): JsonResponse
    {
        $this->exigirQuePuedaVerLaCuenta($request, $matricula);

        $datos = $request->validate([
            'pasarela' => ['required', 'string', 'max:30'],
            'adeudo_ids' => ['required', 'array', 'min:1'],
            'adeudo_ids.*' => ['integer'],
            // Sólo lo mandan las pasarelas que exigen saberlo de antemano.
            'metodo' => ['nullable', 'string', 'max:20'],
        ], [
            'adeudo_ids.required' => 'Elige al menos un cargo para pagar.',
        ]);

        try {
            $intencion = $this->cobro->iniciar(
                $matricula,
                $datos['pasarela'],
                $datos['adeudo_ids'],
                route('tenant.pagos.retorno'),
                route('tenant.pagos.aviso', ['pasarela' => $datos['pasarela']]),
                $datos['metodo'] ?? null,
            );
        } catch (RuntimeException $e) {
            /*
             * Lo que falló es de la escuela, no de quien paga: credenciales
             * caducadas, una pasarela mal configurada, su servicio caído. El
             * motivo exacto —«api key inválida»— va al registro, donde sirve
             * para arreglarlo; a quien iba a pagar se le dice lo único que le
             * incumbe, que es que no es culpa suya y a quién avisar.
             *
             * Sin esto llegaba un 500 y el panel enseñaba un error genérico:
             * el alumno se quedaba pensando que hizo algo mal.
             */
            Log::error('No se pudo abrir un cobro en línea.', [
                'pasarela' => $datos['pasarela'],
                'matricula' => $matricula->id,
                'motivo' => $e->getMessage(),
            ]);

            return response()->json([
                'motivo' => 'No se pudo abrir el pago con '.PasarelasCatalogo::nombreDe($datos['pasarela'])
                    .'. No es problema tuyo: avísale a la escuela para que lo revise.',
            ], 422);
        }

        return response()->json(['url' => $intencion->url_pago]);
    }

    /**
     * El navegador vuelve de pagar.
     *
     * Aquí NO se decide nada: esta pantalla la puede abrir cualquiera
     * escribiendo la dirección, así que darla por buena sería regalar
     * colegiaturas. Se aprovecha para PREGUNTARLE a la pasarela —que es lo
     * único que vale— y así el alumno ve su pago aplicado sin esperar al
     * webhook, que a veces tarda.
     */
    public function retorno(Request $request): Response
    {
        $intencion = IntencionCobro::find($request->integer('intencion'));

        if ($intencion === null) {
            return Inertia::render('Finanzas/PagoResultado', [
                'estado' => EstadoCobro::DESCONOCIDO->value,
                'mensaje' => EstadoCobro::DESCONOCIDO->mensaje(),
                'volver' => null,
            ]);
        }

        // Si el webhook ya llegó, esto no hace nada: `conciliar` es idempotente.
        $intencion = $this->cobro->revisar($intencion) ?? $intencion;

        return Inertia::render('Finanzas/PagoResultado', [
            'estado' => $this->comoQuedo($intencion),
            'mensaje' => $this->mensajeDe($intencion),
            'monto' => (float) $intencion->monto,
            'volver' => $intencion->matricula_oferta_id
                ? route('tenant.finanzas.cuenta', ['matricula' => $intencion->matricula_oferta_id])
                : null,
        ]);
    }

    /**
     * El aviso de la pasarela. Esto es lo que de verdad cobra.
     *
     * ── Por qué contesta 200 casi siempre ──────────────────────────────────
     * Una pasarela que no recibe un 200 reintenta, y con razón. Pero reintentar
     * sirve para un fallo pasajero —la base caída, un despliegue a medias—, no
     * para un aviso que nunca vamos a poder procesar: ésos se reintentarían
     * durante días. Así que se contesta «recibido» a lo que ya se atendió o no
     * nos corresponde, y se deja el error sólo para lo que de verdad falló.
     */
    public function aviso(Request $request, string $pasarelaClave): JsonResponse
    {
        $config = PasarelaPago::para($pasarelaClave);
        $pasarela = $this->pasarelas->para($config);

        if (! $pasarela->avisoAutentico($request, $config)) {
            Log::warning('Llegó un aviso de cobro con firma inválida.', [
                'pasarela' => $pasarelaClave,
                'ip' => $request->ip(),
            ]);

            // 401 y no 200: aquí sí conviene que se note.
            return response()->json(['error' => 'firma inválida'], 401);
        }

        $resultado = $pasarela->interpretarAviso($request);

        if (! $resultado instanceof ResultadoCobro) {
            // Un aviso de otra cosa (una suscripción, un contracargo). No es un
            // error: simplemente no es nuestro.
            return response()->json(['recibido' => true]);
        }

        $this->cobro->conciliar($resultado);

        return response()->json(['recibido' => true]);
    }

    /**
     * Cómo pagar, cuando la pasarela no da una página sino datos.
     *
     * Es el caso del SPEI: devuelve CLABE, banco y referencia, y ninguna
     * pantalla donde enseñarlos. Sin esto, quien eligiera transferencia se
     * quedaría con un error genérico en lugar de los datos que venía a buscar.
     */
    public function instrucciones(Request $request, IntencionCobro $intencion): Response
    {
        $matricula = $intencion->matriculaOferta;

        // Los datos para pagar son de quien debe: mismo criterio que la cuenta.
        AvisoParaElUsuario::aMenosQue($matricula !== null, 404, 'Ese cobro ya no existe.');
        $this->exigirQuePuedaVerLaCuenta($request, $matricula);

        return Inertia::render('Finanzas/InstruccionesDePago', [
            'monto' => (float) $intencion->monto,
            'pasarela' => $intencion->pasarela,
            'datos' => $this->datosParaPagar($intencion),
            'volver' => route('tenant.finanzas.cuenta', ['matricula' => $matricula->id]),
        ]);
    }

    /**
     * Los datos de pago que devolvió la pasarela, en pares legibles.
     *
     * Se leen de lo que ella contestó y se traducen aquí porque cada una los
     * llama distinto; lo que ve quien paga tiene que decir «CLABE», no
     * `payment_method.clabe`.
     *
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    private function datosParaPagar(IntencionCobro $intencion): array
    {
        $metodo = $intencion->respuesta['payment_method'] ?? [];

        $etiquetas = [
            'bank' => 'Banco',
            'clabe' => 'CLABE',
            'name' => 'Beneficiario',
            'reference' => 'Referencia',
            'agreement' => 'Convenio',
            'barcode_url' => 'Código de barras',
        ];

        return collect($etiquetas)
            ->map(fn (string $etiqueta, string $campo) => [
                'etiqueta' => $etiqueta,
                'valor' => (string) ($metodo[$campo] ?? ''),
            ])
            ->filter(fn (array $d) => $d['valor'] !== '')
            ->values()
            ->all();
    }

    /**
     * La pasarela de mentira, para recorrer el flujo sin cobrarle a nadie.
     *
     * Sólo existe en modo de pruebas: fuera de él responde 404, porque una
     * pantalla que aprueba pagos con un clic no debe ni asomarse en producción.
     */
    public function simulador(Request $request, IntencionCobro $intencion): Response
    {
        AvisoParaElUsuario::aMenosQue(
            config('pagos.modo') === 'fake',
            404,
            'Esta pantalla sólo existe cuando el cobro en línea está en modo de pruebas.',
        );

        return Inertia::render('Finanzas/PagoSimulador', [
            'intencion' => [
                'id' => $intencion->id,
                'monto' => (float) $intencion->monto,
                'pasarela' => $intencion->pasarela,
                'estado' => $intencion->estado,
            ],
            'estados' => [
                ['valor' => EstadoCobro::APROBADO->value, 'texto' => 'Aprobar el pago'],
                ['valor' => EstadoCobro::PENDIENTE->value, 'texto' => 'Dejarlo en proceso (SPEI, efectivo)'],
                ['valor' => EstadoCobro::RECHAZADO->value, 'texto' => 'Rechazarlo (tarjeta declinada)'],
            ],
        ]);
    }

    /**
     * El simulador «paga»: manda el aviso por el mismo camino que la pasarela
     * real, para que lo que se ejercite sea el flujo de verdad y no un atajo.
     */
    public function simular(Request $request, IntencionCobro $intencion): RedirectResponse
    {
        AvisoParaElUsuario::aMenosQue(
            config('pagos.modo') === 'fake',
            404,
            'Esta pantalla sólo existe cuando el cobro en línea está en modo de pruebas.',
        );

        $datos = $request->validate([
            'estado' => ['required', 'string'],
        ]);

        $pasarela = $this->pasarelas->para(PasarelaPago::para($intencion->pasarela));

        $resultado = $pasarela->interpretarAviso(
            $request->merge(['intencion' => $intencion->id, 'estado' => $datos['estado']]),
        );

        if ($resultado instanceof ResultadoCobro) {
            $this->cobro->conciliar($resultado);
        }

        return redirect()->route('tenant.pagos.retorno', ['intencion' => $intencion->id]);
    }

    // ── Interno ────────────────────────────────────────────────────────────

    private function comoQuedo(IntencionCobro $intencion): string
    {
        return match ($intencion->estado) {
            IntencionCobro::PAGADA => EstadoCobro::APROBADO->value,
            IntencionCobro::FALLIDA => EstadoCobro::RECHAZADO->value,
            IntencionCobro::CANCELADA => EstadoCobro::CANCELADO->value,
            default => EstadoCobro::PENDIENTE->value,
        };
    }

    private function mensajeDe(IntencionCobro $intencion): string
    {
        return (EstadoCobro::tryFrom($this->comoQuedo($intencion)) ?? EstadoCobro::DESCONOCIDO)->mensaje();
    }
}
