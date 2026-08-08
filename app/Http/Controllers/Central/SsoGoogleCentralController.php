<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\Sso\EstadoDeGoogle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * El único retorno de Google que hay que registrar en su consola.
 *
 * Google vuelve SIEMPRE aquí, sea cual sea la escuela desde la que se entró, y
 * este controlador reparte: mira el sobre firmado que viajó de ida y vuelta,
 * saca de él a qué escuela pertenece la sesión, y manda ahí el código de
 * autorización para que ella lo canjee.
 *
 * ── No canjea el código: lo reparte ────────────────────────────────────────
 * Podría canjearlo aquí y entrar por su cuenta, pero la sesión tiene que
 * quedar en el dominio de la escuela —es donde vive su cookie— y las cuentas
 * están en su base de datos, no en la central. Así que este sitio no sabe nada
 * de usuarios: sólo sabe a dónde va cada quien.
 *
 * ── La única defensa que importa ───────────────────────────────────────────
 * Redirigir a donde diga un parámetro de la URL es el clásico «open redirect»,
 * y con un código de autorización dentro es peor: se lo estarías entregando a
 * quien puso el destino. Por eso el destino sale de un sobre FIRMADO y, además,
 * se comprueba que ese dominio sea de una escuela que existe. Las dos cosas: la
 * firma dice que el sobre lo hicimos nosotros, y la comprobación dice que
 * apunta a un sitio nuestro aunque la llave se filtrara.
 */
class SsoGoogleCentralController extends Controller
{
    public function __construct(private readonly EstadoDeGoogle $estado) {}

    public function callback(Request $request): RedirectResponse
    {
        /*
         * Google avisa aquí también cuando alguien cancela («access_denied»).
         * No es un error del sistema y no debe acabar en una pantalla rota:
         * como no se sabe de qué escuela venía si no hay sobre, se cae al
         * dominio central.
         */
        $dominio = $this->estado->abrir($request->query('state'));

        if ($dominio === null) {
            Log::info('Retorno de Google sin sobre válido.', ['ip' => $request->ip()]);

            return redirect('/')->with('error', 'El acceso con Google caducó o no se pudo verificar. Inténtalo de nuevo.');
        }

        /*
         * El dominio tiene que ser de una escuela REAL. Sin esto, quien
         * consiguiera fabricar un sobre podría hacer que le mandemos el código
         * a su propio servidor.
         */
        if (! Domain::query()->where('domain', $dominio)->exists()) {
            Log::warning('Retorno de Google apuntando a un dominio desconocido.', ['dominio' => $dominio]);

            return redirect('/')->with('error', 'El acceso con Google no se pudo completar.');
        }

        $codigo = (string) $request->query('code', '');

        if ($codigo === '') {
            // Canceló en la pantalla de Google. Se le devuelve a su escuela sin
            // ruido: no hizo nada malo, cambió de opinión.
            return redirect($this->urlDeLaEscuela($dominio, '/'));
        }

        return redirect($this->urlDeLaEscuela($dominio, '/auth/google/entrada').'?'.http_build_query([
            'code' => $codigo,
            'state' => $request->query('state'),
        ]));
    }

    /**
     * El esquema lo decide el entorno, no la petición.
     *
     * En local se entra por http y en producción por https; tomarlo de la
     * petición dejaría que un proxy mal configurado degradara el destino a http
     * justo en el salto que lleva el código de autorización.
     */
    private function urlDeLaEscuela(string $dominio, string $ruta): string
    {
        $esquema = app()->environment('production') ? 'https' : $this->esquemaLocal();

        return "{$esquema}://{$dominio}{$ruta}";
    }

    private function esquemaLocal(): string
    {
        return str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http';
    }
}
