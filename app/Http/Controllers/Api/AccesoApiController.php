<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Services\Acceso\ResolutorDeCuenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El acceso de la app móvil, ya sobre el dominio de la escuela.
 *
 * A diferencia de la web (`AutenticacionController`), aquí NO se abre sesión: se
 * emite un TOKEN de Sanctum. La regla de CÓMO se encuentra la cuenta —correo o
 * CURP, prefiriendo la real sobre la de censo— es la MISMA que la web, y por eso
 * vive en `ResolutorDeCuenta`: escrita dos veces, la app dejaría entrar por una
 * puerta que la web cierra.
 */
class AccesoApiController extends Controller
{
    public function __construct(private readonly ResolutorDeCuenta $resolutor) {}

    /**
     * Cambia credenciales por un token. Con la misma limitación por intentos que
     * la web (5 por identificador+IP): la pantalla de acceso no es un oráculo de
     * fuerza bruta.
     */
    public function acceso(Request $peticion): JsonResponse
    {
        $datos = $peticion->validate([
            'identificador' => ['required', 'string'],
            'password' => ['required', 'string'],
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ]);

        $llave = $this->llaveDeIntentos((string) $datos['identificador'], (string) $peticion->ip());

        if (RateLimiter::tooManyAttempts($llave, 5)) {
            $segundos = RateLimiter::availableIn($llave);

            throw ValidationException::withMessages([
                'identificador' => "Demasiados intentos. Vuelve a intentar en {$segundos} segundos.",
            ]);
        }

        $usuario = $this->resolutor->porIdentificador((string) $datos['identificador']);

        // Se verifica el hash igual que `Auth::attempt` en la web (mismo hasher,
        // mismo campo `password`), sin abrir sesión. Una cuenta de censo no tiene
        // contraseña que coincida, así que cae aquí con su mensaje.
        if ($usuario === null || ! Hash::check((string) $datos['password'], (string) $usuario->password)) {
            RateLimiter::hit($llave);

            throw ValidationException::withMessages([
                'identificador' => $this->resolutor->mensajeDeFallo($usuario),
            ]);
        }

        RateLimiter::clear($llave);

        $token = $usuario->createToken((string) ($datos['dispositivo'] ?? 'app-movil'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => $this->comoArray($usuario),
        ]);
    }

    /** Quién soy: lo consulta la app al arrancar con un token guardado. */
    public function yo(Request $peticion): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        return response()->json(['usuario' => $this->comoArray($usuario)]);
    }

    /** Cierra la sesión de ESTE dispositivo: revoca sólo el token en uso. */
    public function salir(Request $peticion): JsonResponse
    {
        $peticion->user()->currentAccessToken()->delete();

        return response()->json(['mensaje' => 'Sesión cerrada en este dispositivo.']);
    }

    /**
     * Lo que la app necesita para saber quién entró y a qué portal llevarlo. Las
     * FACETAS (alumno, docente, padre…) son lo que decide la interfaz; los
     * permisos finos viajan con cada pantalla, no aquí.
     *
     * @return array<string, mixed>
     */
    private function comoArray(Usuario $usuario): array
    {
        $roles = $usuario->rolesDisponibles();

        return [
            'id' => $usuario->id,
            'persona_id' => $usuario->persona_id,
            'nombre' => $usuario->persona?->nombreCompleto(),
            'email' => $usuario->email,
            'facetas' => $roles
                ->map(fn (Rol $r) => $r->faceta())
                ->unique('id')
                ->map(fn (Rol $f) => ['clave' => $f->name, 'nombre' => $f->nombre])
                ->values(),
            'roles' => $roles
                ->map(fn (Rol $r) => ['id' => $r->id, 'nombre' => $r->nombre, 'faceta' => $r->faceta()->name])
                ->values(),
            'rol_activo_id' => $usuario->rol_activo_id,
        ];
    }

    private function llaveDeIntentos(string $identificador, string $ip): string
    {
        return 'api-acceso|'.Str::transliterate(Str::lower($identificador).'|'.$ip);
    }
}
