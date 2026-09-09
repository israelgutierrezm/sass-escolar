<?php

declare(strict_types=1);

namespace App\Services\Acceso;

use App\Models\Identidad\Usuario;
use Illuminate\Support\Str;

/**
 * Cómo se encuentra una cuenta a partir de lo que teclea quien entra.
 *
 * Vive en UN sitio porque lo preguntan DOS puertas —el formulario web
 * (`LoginRequest`) y la API de la app móvil (`AccesoApiController`)—, y es una
 * regla de seguridad: escrita dos veces, la app podría dejar entrar por CURP a
 * quien la web no, o preferir la cuenta de censo sobre la real en una y no en la
 * otra. Es la lección de `estaEnVigor` y de `PuedeRecoger`.
 */
class ResolutorDeCuenta
{
    /**
     * La cuenta que corresponde al identificador: por CORREO, o por CURP como
     * alternativa (no todos tienen CURP, y puede repetirse; el correo es el
     * principal). Se prefiere una cuenta con acceso configurado cuando dos
     * comparten correo, para que la de censo no le gane a la real.
     */
    public function porIdentificador(string $identificador): ?Usuario
    {
        $identificador = trim($identificador);

        if ($identificador === '') {
            return null;
        }

        $consulta = Usuario::query()->orderByDesc('acceso_configurado');

        if (Str::contains($identificador, '@')) {
            return $consulta->where('email', $identificador)->first();
        }

        return $consulta
            ->whereHas('persona', fn ($p) => $p->where('curp', strtoupper($identificador)))
            ->first();
    }

    /**
     * El mensaje cuando el acceso NO procede. Distingue la cuenta de CENSO
     * —existe pero todavía no tiene contraseña, así que ninguna entra— de un
     * credencial que no coincide. Uno solo, para que el formulario y la app
     * digan lo mismo.
     */
    public function mensajeDeFallo(?Usuario $usuario): string
    {
        if ($usuario !== null && ! $usuario->acceso_configurado) {
            return 'Tu cuenta todavía no tiene acceso configurado. Pídele a tu escuela que te lo habilite.';
        }

        return 'Las credenciales no coinciden con nuestros registros.';
    }
}
