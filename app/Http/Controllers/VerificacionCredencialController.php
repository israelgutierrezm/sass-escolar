<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Credencial\CatalogoCampos;
use App\Credencial\CredencialesDeLaPersona;
use App\Models\Identidad\Credencial;
use App\Models\Identidad\CredencialRol;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La página a la que lleva el QR de una credencial.
 *
 * ── Para qué sirve ─────────────────────────────────────────────────────────
 * Para confirmar que el gafete que alguien trae en la mano es de verdad y es
 * suyo: quien lo escanea ve la foto y el nombre que la ESCUELA tiene guardados,
 * no los que están impresos en el plástico. Un gafete alterado se cae solo,
 * porque lo impreso deja de coincidir con lo que responde el servidor.
 *
 * ── Por qué va en Blade y fuera de la SPA ──────────────────────────────────
 * Por la misma razón que el formulario público: esto lo abre la cámara del
 * teléfono de quien revisa en la puerta, que puede no tener sesión y que
 * necesita ver la foto en el primer segundo. Montar el panel administrativo
 * para eso sería medio megabyte de JavaScript por cada persona que entra.
 *
 * ── Las dos puertas ────────────────────────────────────────────────────────
 * `qr_activo` decide si la página existe siquiera; `qr_publico`, si hace falta
 * sesión para verla. Las dos las configura la escuela, y la diferencia no es
 * cosmética: en un plantel donde el gafete lo revisa personal con cuenta,
 * exigir sesión evita que el QR sirva de directorio a cualquiera que fotografíe
 * una credencial ajena en el transporte público.
 */
class VerificacionCredencialController extends Controller
{
    public function __construct(private readonly CredencialesDeLaPersona $credenciales) {}

    public function mostrar(string $uuid): Renderable|RedirectResponse
    {
        [$credencial, $config, $desvio] = $this->resolver($uuid);

        if ($desvio !== null) {
            return $desvio;
        }

        return view('publico.credencial', [
            'credencial' => $credencial,
            'valores' => CatalogoCampos::publicos(CatalogoCampos::valores(
                $credencial->persona,
                $credencial->rol,
                $credencial->matricula,
                $config->vigencia,
            )),
            'etiquetas' => array_map(fn (array $c) => $c['etiqueta'], CatalogoCampos::todos()),
            'tieneFoto' => filled($credencial->persona->foto_url),
        ]);
    }

    /**
     * La foto de esa credencial.
     *
     * No se puede reusar `/personas/{id}/foto`: aquel exige sesión —es el del
     * expediente— y además va por id, así que serviría de índice para pedir la
     * foto de cualquiera. Éste va por el uuid de la credencial y pasa por las
     * MISMAS dos puertas que la ficha: si la escuela cerró el QR, la foto
     * tampoco sale.
     */
    public function foto(string $uuid): StreamedResponse|Response|RedirectResponse
    {
        [$credencial, , $desvio] = $this->resolver($uuid);

        if ($desvio !== null) {
            return $desvio;
        }

        $ruta = $credencial->persona->foto_url;

        abort_if(blank($ruta) || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->response($ruta);
    }

    /**
     * Busca la credencial y decide si se puede enseñar.
     *
     * Devuelve el desvío en vez de lanzarlo para que las dos entradas —la ficha
     * y la foto— apliquen exactamente el mismo criterio. Que la foto se
     * defendiera «parecido» es como se llega a que la página pida sesión y la
     * imagen no.
     *
     * @return array{0: ?Credencial, 1: ?CredencialRol, 2: ?RedirectResponse}
     */
    private function resolver(string $uuid): array
    {
        $credencial = Credencial::query()
            ->with([
                'persona',
                'rol',
                'matricula.oferta.carrera:id,nombre,nivel_estudios_id',
                'matricula.oferta.campus:id,nombre',
            ])
            ->where('uuid', $uuid)
            ->first();

        abort_if($credencial?->persona === null || $credencial->rol === null, 404);

        $config = $this->credenciales->configuracion(
            $credencial->rol_id,
            $credencial->matricula?->oferta?->carrera?->nivel_estudios_id,
        );

        /*
         * Sin configuración, apagada o con el QR desactivado: 404, no 403.
         *
         * Un 403 confirma que ese uuid existe y que hay alguien detrás, que es
         * justo lo que no conviene decirle a quien esté probando direcciones. Y
         * si la escuela apagó el QR, la respuesta honesta es que esa página no
         * existe.
         */
        abort_unless($config?->emitible() && $config->qr_activo, 404);

        return [
            $credencial,
            $config,
            // Con la dirección guardada: al entrar cae en la ficha que quería
            // ver y no en el panel, preguntándose qué pasó con el escaneo.
            $config->qr_publico || Auth::check() ? null : redirect()->guest(route('tenant.login')),
        ];
    }
}
