<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Services\Grabaciones\RecolectorDeGrabaciones;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El aviso de Zoom de que una grabación ya está lista.
 *
 * ── Público y sin CSRF, como el de la pasarela de pago ─────────────────────
 * Lo manda un servidor de Zoom, que no tiene sesión ni cookie ni token de
 * formulario. Exigirle cualquiera de las tres es rechazar todos los avisos y no
 * archivar nunca nada.
 *
 * ── Que sea público NO lo hace confiable ───────────────────────────────────
 * Aquí la defensa no puede ser la misma que en los pagos —allá el aviso sólo
 * dice QUÉ preguntar y la respuesta sale de consultarle a la pasarela—, porque
 * el cuerpo trae la URL de descarga y ésa es la que se usa. Así que se comprueba
 * la FIRMA: Zoom manda un HMAC del cuerpo con el token secreto de la escuela, y
 * sin él no se hace nada. Un aviso falso sin firma válida se descarta.
 *
 * Sin secreto configurado, el aviso se rechaza en vez de aceptarse a ciegas:
 * aceptar sin firma convertiría este endpoint en «descárgame lo que yo diga»,
 * que es un servidor haciendo peticiones a donde le manden.
 *
 * ── El 200 sale rápido ─────────────────────────────────────────────────────
 * Zoom reintenta el aviso si tarda, y cada reintento sería otra descarga del
 * mismo video. Aquí sólo se anota y se encola.
 */
class AvisoGrabacionController extends Controller
{
    public function __construct(private readonly RecolectorDeGrabaciones $recolector) {}

    public function zoom(Request $request): JsonResponse
    {
        $integracion = IntegracionVideo::para(ProveedoresVideoCatalogo::ZOOM);
        $secreto = $integracion->credencialesArray()['webhook_secret'] ?? null;

        if (blank($secreto)) {
            Log::warning('Llegó un aviso de grabación de Zoom sin secreto configurado');

            return response()->json(['error' => 'sin secreto configurado'], 403);
        }

        /*
         * El apretón de manos de Zoom.
         *
         * Al registrar la URL, Zoom manda un `endpoint.url_validation` con un
         * `plainToken` y espera de vuelta ese mismo token y su HMAC. Sin esto la
         * URL no se puede dar de alta, y el resto del endpoint no llega a
         * usarse nunca.
         */
        if ($request->input('event') === 'endpoint.url_validation') {
            $plano = (string) $request->input('payload.plainToken');

            return response()->json([
                'plainToken' => $plano,
                'encryptedToken' => hash_hmac('sha256', $plano, $secreto),
            ]);
        }

        if (! $this->firmaValida($request, $secreto)) {
            Log::warning('Aviso de grabación con firma inválida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'firma inválida'], 401);
        }

        if ($request->input('event') !== 'recording.completed') {
            // Otros eventos no interesan y se contestan con 200: un 4xx haría
            // que Zoom marcara el endpoint como roto y dejara de avisar.
            return response()->json(['ok' => true]);
        }

        $reunion = (string) $request->input('payload.object.id');
        $clase = Videoconferencia::query()->where('meeting_id', $reunion)->first();

        if ($clase === null) {
            // Una reunión que no salió de Acadion. No es un error: la misma
            // cuenta de Zoom puede usarse para otras cosas.
            Log::info('Aviso de grabación de una reunión que no es de Acadion', ['reunion' => $reunion]);

            return response()->json(['ok' => true]);
        }

        $archivos = collect($request->input('payload.object.recording_files', []))
            ->filter(fn ($a) => filled($a['download_url'] ?? null))
            ->map(fn ($a) => [
                'id' => (string) $a['id'],
                'tipo' => RecolectorDeGrabaciones::tipoDesdeZoom((string) ($a['file_type'] ?? '')),
                'nombre' => $this->nombreDe($clase, $a),
                'bytes' => isset($a['file_size']) ? (int) $a['file_size'] : null,
                'url' => (string) $a['download_url'],
            ])
            ->values()
            ->all();

        /*
         * El `download_token` viene EN EL AVISO y es lo único con que se puede
         * bajar: las URL de descarga de Zoom no son públicas. Viaja con el
         * trabajo porque caduca —releerlo después no serviría—.
         */
        $this->recolector->registrar(
            $clase,
            ProveedoresVideoCatalogo::ZOOM,
            $archivos,
            $request->input('download_token'),
        );

        return response()->json(['ok' => true]);
    }

    /**
     * La firma de Zoom: HMAC del cuerpo crudo con el secreto.
     *
     * Se usa el cuerpo CRUDO y no el arreglo ya interpretado: volver a
     * serializarlo cambiaría un espacio o el orden de una llave y la firma no
     * cuadraría nunca. Y la comparación va con `hash_equals`, que no se puede
     * medir con un cronómetro.
     */
    private function firmaValida(Request $request, string $secreto): bool
    {
        $marca = $request->header('x-zm-request-timestamp');
        $firma = $request->header('x-zm-signature');

        if (blank($marca) || blank($firma)) {
            return false;
        }

        // Una firma vieja no se acepta: sin esto, quien capture un aviso legítimo
        // puede reenviarlo cuando quiera y hacernos descargar otra vez.
        if (abs(now()->timestamp - (int) $marca) > 300) {
            return false;
        }

        $esperada = 'v0='.hash_hmac('sha256', "v0:{$marca}:".$request->getContent(), $secreto);

        return hash_equals($esperada, $firma);
    }

    /** «2026-08-19 Sistemas Operativos.mp4» — legible al buscarlo en la nube. */
    private function nombreDe(Videoconferencia $clase, array $archivo): string
    {
        $extension = strtolower((string) ($archivo['file_extension'] ?? $archivo['file_type'] ?? 'mp4'));
        $fecha = $clase->inicio?->format('Y-m-d') ?? 'sin-fecha';
        $titulo = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $clase->titulo);

        return mb_substr("{$fecha} {$titulo}", 0, 150).'.'.$extension;
    }
}
