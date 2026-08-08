<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use App\Services\IniciadorSesion;
use App\Services\Sso\EstadoDeGoogle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * SSO de Google para el acceso a una ESCUELA (tenant).
 *
 * NO crea cuentas: autentica una que YA existe, emparejando por CORREO. Así se
 * respeta la unicidad del correo (es único en `usuarios`) y no se cuela un
 * duplicado por otra vía. Si el correo de Google no tiene cuenta en la escuela,
 * se avisa y no se entra.
 *
 * Modos (services.google.modo): real (OAuth), fake (simulado, para local donde
 * los subdominios *.localhost no valen como redirect_uri) y off.
 */
class SsoGoogleController extends Controller
{
    public function __construct(private readonly EstadoDeGoogle $estado) {}

    /** Arranca el flujo: manda a Google (real) o simula el retorno (fake). */
    public function redirigir(Request $request): RedirectResponse
    {
        $modo = config('services.google.modo');

        if ($modo === 'off') {
            return redirect()->route('tenant.login')->with('error', 'El acceso con Google no está habilitado.');
        }

        if ($modo === 'fake') {
            // Simula lo que Google devolvería: el correo se toma del query
            // (?email=) o del configurado, para probar sin OAuth real.
            $email = $request->query('email') ?: config('services.google.fake_email', 'demo@escuela.mx');

            return redirect()->route('tenant.sso.google.callback', ['fake_email' => $email]);
        }

        /*
         * Se sale con el sobre puesto.
         *
         * Google devolverá al dominio CENTRAL —la única URI registrada en su
         * consola— y allí no habría forma de saber de qué escuela vino esta
         * sesión. El sobre lleva esa información, firmada, y Google lo devuelve
         * intacto en `state`.
         */
        return Socialite::driver('google')
            ->redirectUrl($this->urlCallback())
            ->with(['state' => $this->estado->crear($request->getHost())])
            ->stateless()
            ->redirect();
    }

    /**
     * Vuelta desde el dominio central, ya con el código en la mano.
     *
     * El canje se hace AQUÍ y no en la central porque la sesión tiene que
     * quedar en la cookie de esta escuela y las cuentas viven en su base.
     */
    public function entrada(Request $request, IniciadorSesion $iniciador): RedirectResponse
    {
        if (config('services.google.modo') !== 'real') {
            return redirect()->route('tenant.login');
        }

        /*
         * El sobre se vuelve a abrir aquí, y no basta con que la central ya lo
         * hubiera validado: a esta ruta se puede llegar directamente. Y se
         * comprueba que el sobre sea PARA ESTA escuela —si no, un sobre legítimo
         * de otra serviría para entrar aquí—.
         */
        $dominio = $this->estado->abrir($request->query('state'));

        if ($dominio === null || $dominio !== $request->getHost()) {
            return redirect()->route('tenant.login')
                ->with('error', 'El acceso con Google caducó o no se pudo verificar. Inténtalo de nuevo.');
        }

        try {
            /*
             * `redirectUrl` tiene que ser la MISMA que se usó al pedir el
             * código —la central—, aunque ya no vayamos a volver ahí: Google la
             * compara al canjear y rechaza el canje si no coincide.
             */
            $correo = (string) Socialite::driver('google')
                ->redirectUrl($this->urlCallback())
                ->stateless()
                ->user()
                ->getEmail();
        } catch (Throwable) {
            return redirect()->route('tenant.login')
                ->with('error', 'No se pudo completar el acceso con Google. Intenta de nuevo.');
        }

        return $this->entrar($request, $iniciador, $correo);
    }

    /**
     * Retorno directo a la escuela. Hoy sólo lo usa el modo `fake`.
     *
     * En modo real Google ya no vuelve aquí: vuelve al dominio central, que
     * reparte a `entrada`. Se conserva porque el modo simulado no pasa por
     * Google y no tiene por qué dar el rodeo.
     */
    public function callback(Request $request, IniciadorSesion $iniciador): RedirectResponse
    {
        $modo = config('services.google.modo');

        if ($modo === 'off') {
            return redirect()->route('tenant.login');
        }

        try {
            $correo = $modo === 'fake'
                ? (string) $request->query('fake_email')
                : (string) Socialite::driver('google')->redirectUrl($this->urlCallback())->stateless()->user()->getEmail();
        } catch (Throwable) {
            return redirect()->route('tenant.login')->with('error', 'No se pudo completar el acceso con Google. Intenta de nuevo.');
        }

        return $this->entrar($request, $iniciador, $correo);
    }

    /**
     * Empareja el correo con una cuenta de ESTA escuela y abre la sesión.
     *
     * Es lo único que hacen en común el retorno simulado y el real, y por eso
     * vive aquí: las reglas de quién entra —cuenta existente, acceso
     * configurado— no pueden depender de por qué puerta se llegó.
     */
    private function entrar(Request $request, IniciadorSesion $iniciador, string $correo): RedirectResponse
    {
        $correo = mb_strtolower(trim($correo));

        if ($correo === '') {
            return redirect()->route('tenant.login')->with('error', 'Google no devolvió un correo válido.');
        }

        // Emparejar por correo (único). No se crea nada: si no hay cuenta, no entra.
        $usuario = Usuario::query()->whereRaw('lower(email) = ?', [$correo])->first();

        if ($usuario === null) {
            return redirect()->route('tenant.login')
                ->with('error', "No hay una cuenta con el correo {$correo} en esta escuela. Pide que te den de alta.");
        }

        // Misma puerta que el login por contraseña: una cuenta de censo (sin
        // acceso configurado) no entra ni por Google.
        if (! $usuario->acceso_configurado) {
            return redirect()->route('tenant.login')
                ->with('error', 'Tu cuenta todavía no tiene acceso configurado. Pídele a tu escuela que te lo habilite.');
        }

        Auth::login($usuario, true);
        $request->session()->regenerate();
        $iniciador->finalizar($usuario, $request);

        return redirect()->intended(route('tenant.dashboard'));
    }

    /** URL de retorno de ESTA escuela (su propio subdominio). */
    private function urlCallback(): string
    {
        return config('services.google.redirect') ?: url('/auth/google/callback');
    }
}
