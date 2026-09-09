<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Acceso\ResolutorDeCuenta;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validación e intento de acceso.
 *
 * Se entra con el CORREO. La CURP se acepta como alternativa para quien no lo
 * recuerde, pero el correo es el identificador principal: no todos tienen CURP
 * (extranjeros) y puede repetirse. El nombre de usuario ya NO sirve para entrar.
 *
 * Lleva limitación por intentos (5 por combinación identificador+IP) para que la
 * pantalla de acceso no sea un oráculo de fuerza bruta.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identificador' => ['required', 'string'],
            'password' => ['required', 'string'],
            'recordarme' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'identificador' => 'correo',
            'password' => 'contraseña',
        ];
    }

    /**
     * Autentica al usuario o lanza un error de validación.
     */
    public function autenticar(): void
    {
        $this->asegurarQueNoEstaBloqueado();

        $resolutor = app(ResolutorDeCuenta::class);
        $usuario = $resolutor->porIdentificador((string) $this->input('identificador'));
        $password = (string) $this->input('password');

        // Se autentica por id ya resuelto: así el correo y la CURP comparten un
        // solo camino y el hash de la contraseña se valida igual en ambos.
        if ($usuario === null || ! Auth::attempt(['id' => $usuario->id, 'password' => $password], $this->boolean('recordarme'))) {
            RateLimiter::hit($this->llaveDeIntentos());

            // El mensaje sale del resolutor, para que la web y la app digan lo
            // mismo (incluida la cuenta de censo, que existe sin contraseña).
            throw ValidationException::withMessages([
                'identificador' => $resolutor->mensajeDeFallo($usuario),
            ]);
        }

        RateLimiter::clear($this->llaveDeIntentos());
    }

    private function asegurarQueNoEstaBloqueado(): void
    {
        if (! RateLimiter::tooManyAttempts($this->llaveDeIntentos(), 5)) {
            return;
        }

        event(new Lockout($this));

        $segundos = RateLimiter::availableIn($this->llaveDeIntentos());

        throw ValidationException::withMessages([
            'identificador' => "Demasiados intentos. Vuelve a intentar en {$segundos} segundos.",
        ]);
    }

    private function llaveDeIntentos(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('identificador')).'|'.$this->ip());
    }
}
