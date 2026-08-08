<?php

declare(strict_types=1);

namespace App\Services\Sso;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El sobre que viaja con el usuario hasta Google y vuelve.
 *
 * ── Qué problema resuelve ──────────────────────────────────────────────────
 * Google exige registrar cada URI de retorno, una por una, en su consola. Con
 * un subdominio por escuela eso significa dar de alta una URI cada vez que se
 * abre una escuela nueva —y que nadie pueda entrar con Google hasta que alguien
 * se acuerde de hacerlo—.
 *
 * La salida es registrar UNA sola URI, la del dominio central, y que desde ahí
 * se reparta a la escuela que corresponda. Pero entonces el retorno llega a un
 * sitio que no sabe de dónde venía: hay que llevar esa información con el
 * usuario. Eso es este sobre, que Google devuelve intacto en el parámetro
 * `state`.
 *
 * ── Por qué va FIRMADO ─────────────────────────────────────────────────────
 * El `state` lo puede escribir cualquiera: es texto en una URL. Sin firma, se
 * fabrica uno que diga «vengo de la escuela X» y el dominio central redirige
 * ahí con un código de autorización válido —o peor, a un dominio de fuera, que
 * es la puerta clásica para robar el código—. La firma se hace con la llave de
 * la aplicación, que sólo tiene el servidor.
 *
 * ── Y por qué CADUCA ───────────────────────────────────────────────────────
 * Un sobre firmado sin fecha sirve para siempre: quien capture una URL de
 * retorno podría reusarla meses después. Cinco minutos es de sobra para entrar
 * con Google y demasiado poco para guardarlo y usarlo luego.
 */
class EstadoDeGoogle
{
    /** Cuánto vale un sobre. Entrar con Google toma segundos. */
    private const VIGENCIA = 300;

    /**
     * Arma el sobre para la escuela indicada.
     *
     * El `nonce` no lo comprueba nadie contra una sesión —la del tenant y la
     * del dominio central son distintas, viven en cookies distintas—, pero hace
     * que dos intentos seguidos produzcan sobres distintos, así que uno no se
     * puede confundir con otro ni reusar.
     */
    public function crear(string $dominioEscuela): string
    {
        $datos = [
            'd' => $dominioEscuela,
            'exp' => now()->addSeconds(self::VIGENCIA)->timestamp,
            'n' => Str::random(16),
        ];

        $cuerpo = $this->codificar($datos);

        return $cuerpo.'.'.$this->firmar($cuerpo);
    }

    /**
     * Abre el sobre. Devuelve el dominio de la escuela, o `null` si no es de
     * fiar.
     *
     * Se devuelve `null` en vez de lanzar porque quien llama tiene que poder
     * mandar a alguien a la pantalla de acceso con un mensaje entendible: un
     * sobre caducado es lo que pasa cuando se deja el navegador abierto media
     * hora, no un ataque.
     */
    public function abrir(?string $sobre): ?string
    {
        if (blank($sobre) || ! str_contains($sobre, '.')) {
            return null;
        }

        [$cuerpo, $firma] = explode('.', $sobre, 2);

        // Constante en tiempo: comparar con `===` filtra información sobre la
        // firma correcta a quien mida cuánto tarda en fallar.
        if (! hash_equals($this->firmar($cuerpo), $firma)) {
            Log::warning('Llegó un retorno de Google con una firma que no cuadra.');

            return null;
        }

        $datos = json_decode(base64_decode(strtr($cuerpo, '-_', '+/'), true) ?: '', true);

        if (! is_array($datos) || blank($datos['d'] ?? null)) {
            return null;
        }

        if (($datos['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        return (string) $datos['d'];
    }

    /** @param  array<string, mixed>  $datos */
    private function codificar(array $datos): string
    {
        return rtrim(strtr(base64_encode(json_encode($datos)), '+/', '-_'), '=');
    }

    private function firmar(string $cuerpo): string
    {
        return hash_hmac('sha256', $cuerpo, (string) config('app.key'));
    }
}
