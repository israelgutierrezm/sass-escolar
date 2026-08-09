<?php

declare(strict_types=1);

namespace App\Support;

/**
 * La dirección por la que el mundo de fuera nos alcanza.
 *
 * ── Por qué no basta con `route()` ─────────────────────────────────────────
 * `route()` arma la URL con el host de la petición en curso. Para una liga que
 * se le enseña a quien está navegando eso es correcto. Para el aviso de una
 * pasarela NO: esa URL no la abre quien navega, la abre un servidor de Mercado
 * Pago desde internet, y el host desde el que la escuela entra —`localhost`, la
 * IP interna, el nombre de la máquina en la red— no existe fuera.
 *
 * El resultado era un aviso que la pasarela nunca podía entregar, y como el
 * cobro sí se abría bien, todo parecía funcionar hasta que el pago no se
 * aplicaba y nadie sabía por qué.
 *
 * Con `PAGOS_URL_PUBLICA` configurada, las URLs que salen hacia una pasarela se
 * reescriben a ese origen. Sin ella, todo sigue como estaba: en producción con
 * un dominio real no hace falta.
 *
 * Sirve para el túnel de desarrollo —ngrok, cloudflared— y también para una
 * instalación detrás de un proxy cuyo nombre público no es el interno.
 */
class UrlPublica
{
    /**
     * La misma ruta, pero con el origen por el que nos alcanzan de fuera.
     *
     * Se conserva todo lo que va después del host —la ruta, la cadena de
     * consulta— porque ahí viaja qué pasarela avisa y de qué cobro.
     */
    public static function paraAfuera(string $url): string
    {
        $base = self::base();

        if ($base === null) {
            return $url;
        }

        $partes = parse_url($url);

        if ($partes === false) {
            return $url;
        }

        $camino = ($partes['path'] ?? '')
            .(isset($partes['query']) ? '?'.$partes['query'] : '');

        return rtrim($base, '/').$camino;
    }

    /** El origen público configurado, ya normalizado. Null si no hay. */
    public static function base(): ?string
    {
        $base = trim((string) config('services.pagos.url_publica', ''));

        if ($base === '') {
            return null;
        }

        /*
         * Sin esquema no es una URL: `abc123.ngrok-free.app` pegado del panel
         * del túnel es lo que uno tiene a mano, y armar `https://` por él evita
         * una configuración que falla sin decir por qué. HTTPS y no HTTP porque
         * ninguna pasarela seria entrega un aviso en claro.
         */
        if (! str_contains($base, '://')) {
            $base = 'https://'.$base;
        }

        return rtrim($base, '/');
    }

    /** El host del origen público, para registrarlo como dominio del tenant. */
    public static function host(): ?string
    {
        $base = self::base();

        return $base === null ? null : (parse_url($base, PHP_URL_HOST) ?: null);
    }
}
