<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validación e intento de acceso.
 *
 * El identificador acepta el nombre de usuario O el correo: en una escuela es
 * común que el alumno recuerde uno y el administrativo el otro.
 *
 * Lleva limitación por intentos (5 por combinación usuario+IP) para que la
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
            'identificador' => 'usuario o correo',
            'password' => 'contraseña',
        ];
    }

    /**
     * Autentica al usuario o lanza un error de validación.
     */
    public function autenticar(): void
    {
        $this->asegurarQueNoEstaBloqueado();

        $identificador = (string) $this->input('identificador');
        $campo = Str::contains($identificador, '@') ? 'email' : 'usuario';

        $credenciales = [
            $campo => $identificador,
            'password' => (string) $this->input('password'),
        ];

        if (! Auth::attempt($credenciales, $this->boolean('recordarme'))) {
            RateLimiter::hit($this->llaveDeIntentos());

            throw ValidationException::withMessages([
                'identificador' => 'Las credenciales no coinciden con nuestros registros.',
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
