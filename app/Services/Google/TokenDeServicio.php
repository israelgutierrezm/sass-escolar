<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Exceptions\AvisoParaElUsuario;
use App\Support\CacheExterno;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Un token de Google, actuando en nombre de una cuenta del dominio.
 *
 * ── Por qué existe como clase ──────────────────────────────────────────────
 * Este mismo JWT estaba escrito DOS veces —en `ProveedorMeet` para Calendar y en
 * `DestinoDrive` para Drive— y ahora hacía falta una tercera, para consultar las
 * grabaciones de Meet. Tres copias de una firma criptográfica es como se llega a
 * que una tenga el `sub` mal y falle sólo en el camino que nadie prueba.
 *
 * ── Qué es la delegación, y por qué el `sub` no es opcional ────────────────
 * Una cuenta de servicio no tiene calendario ni Drive propios: existe para
 * actuar EN NOMBRE de alguien del dominio, y ese alguien va en el `sub`. Sin él,
 * Google devuelve un token válido —de la cuenta de servicio— y las llamadas
 * pasan sin error contra un espacio que no es de nadie: el evento se crea en
 * ninguna parte y no hay nada que lo diga.
 *
 * ── El token se cachea por (escuela, cuenta, alcance) ──────────────────────
 * Vive una hora y se guarda 50 minutos, con diez de margen para que ninguna
 * petición salga con uno que caduca en vuelo. Los tres datos entran en la llave
 * porque los tres lo cambian: dos escuelas no comparten token, actuar como otra
 * persona es otro token, y un alcance distinto también.
 */
class TokenDeServicio
{
    /** Crear el evento de Calendar del que nace el enlace de Meet. */
    public const CALENDAR = 'https://www.googleapis.com/auth/calendar.events';

    /** Preguntar por las grabaciones de una reunión de Meet. */
    public const MEET_LECTURA = 'https://www.googleapis.com/auth/meetings.space.readonly';

    /** Subir archivos a Drive. Sólo alcanza a los que la propia app crea. */
    public const DRIVE_ESCRITURA = 'https://www.googleapis.com/auth/drive.file';

    /** Bajar de Drive lo que Meet dejó grabado, que NO lo creó esta app. */
    public const DRIVE_LECTURA = 'https://www.googleapis.com/auth/drive.readonly';

    /**
     * Un token listo para usar.
     *
     * @param  string  $cuentaServicioJson  el archivo de Google Cloud, tal cual
     * @param  string  $comoQuien  correo del dominio en cuyo nombre se actúa
     * @param  string|array<int, string>  $alcance  uno o varios ámbitos
     */
    public function para(string $cuentaServicioJson, string $comoQuien, string|array $alcance): string
    {
        $json = $this->cuentaDeServicio($cuentaServicioJson);
        $ambitos = implode(' ', (array) $alcance);

        $llave = 'google.token.'.md5((string) tenant('id').$json['client_email'].$comoQuien.$ambitos);

        $token = CacheExterno::recordar($llave, 50, function () use ($json, $comoQuien, $ambitos) {
            $respuesta = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->firmar($json, $comoQuien, $ambitos),
            ]);

            if (! $respuesta->successful()) {
                Log::warning('Google no entregó token', [
                    'estado' => $respuesta->status(),
                    'cuerpo' => $respuesta->body(),
                    'alcance' => $ambitos,
                ]);

                // null y no excepción: `CacheExterno` no guarda los fallos, así
                // que el siguiente intento vuelve a pedirlo.
                return null;
            }

            return $respuesta->json('access_token');
        });

        AvisoParaElUsuario::si(
            blank($token),
            502,
            "Google no aceptó las credenciales para actuar como «{$comoQuien}». Revisa que la cuenta de servicio "
            .'tenga delegación en todo el dominio, y que entre sus alcances esté: '.$ambitos,
        );

        return $token;
    }

    /**
     * El JWT firmado con la llave privada de la cuenta de servicio.
     *
     * @param  array<string, mixed>  $json
     */
    private function firmar(array $json, string $comoQuien, string $ambitos): string
    {
        $ahora = time();

        $sinFirmar = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url((string) json_encode([
                'iss' => $json['client_email'],
                // La delegación: a nombre de QUIÉN se actúa.
                'sub' => $comoQuien,
                'scope' => $ambitos,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $ahora,
                // Google no acepta más de una hora.
                'exp' => $ahora + 3600,
            ]));

        $llave = openssl_pkey_get_private($json['private_key']);

        AvisoParaElUsuario::si(
            $llave === false,
            422,
            'La llave privada de la cuenta de servicio no se pudo leer. Pega el archivo JSON completo, tal como lo '
            .'entrega Google, sin quitarle los saltos de línea.',
        );

        openssl_sign($sinFirmar, $firma, $llave, OPENSSL_ALGO_SHA256);

        return $sinFirmar.'.'.$this->base64Url($firma);
    }

    private function base64Url(string $dato): string
    {
        return rtrim(strtr(base64_encode($dato), '+/', '-_'), '=');
    }

    /**
     * El JSON de la cuenta de servicio, ya interpretado.
     *
     * Se guarda como texto porque es lo que la escuela pega —el archivo tal
     * cual— e interpretarlo aquí permite decirle que está mal cuando lo está, en
     * vez de fallar más adelante con un error de Google que no lo menciona.
     *
     * @return array<string, mixed>
     */
    private function cuentaDeServicio(string $crudo): array
    {
        $json = json_decode($crudo, true);

        AvisoParaElUsuario::si(
            ! is_array($json) || blank($json['client_email'] ?? null) || blank($json['private_key'] ?? null),
            422,
            'El JSON de la cuenta de servicio no es válido: tiene que traer `client_email` y `private_key`. '
            .'Pega el archivo completo que descargaste de Google Cloud.',
        );

        return $json;
    }
}
