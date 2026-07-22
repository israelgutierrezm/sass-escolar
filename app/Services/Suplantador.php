<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * "Ver el sistema como lo ve otra persona".
 *
 * Sirve para soporte real: cuando alguien reporta "no me deja inscribirme", la
 * única forma de reproducir el problema exacto es entrar con sus permisos, su
 * rol activo y sus datos. Un listado de permisos no lo reproduce.
 *
 * Es una capacidad peligrosa y por eso viene con tres cosas que no son
 * opcionales:
 *
 *  1. **Bitácora.** Cada entrada y cada salida quedan en `auditoria` con quién,
 *     a quién, cuándo y desde qué IP. Sin eso, cualquier acción hecha durante
 *     una suplantación sería indistinguible de una hecha por la persona misma.
 *  2. **Banda permanente** en la interfaz. Quien suplanta tiene que saber en
 *     todo momento que no es él; olvidarlo es como se firman actas por error.
 *  3. **Sin escalada ni cadenas.** No se puede suplantar a alguien que también
 *     pueda suplantar —sería una vía para tomar el rol de un igual o superior—
 *     ni suplantar mientras ya se está suplantando.
 *
 * El id del usuario REAL vive en la sesión. Volver es siempre posible y no
 * depende de los permisos del suplantado.
 */
class Suplantador
{
    /** Clave en sesión donde vive el usuario real mientras se suplanta. */
    public const CLAVE_SESION = 'suplantador_id';

    /**
     * Con qué datos pintar el botón «Ver como» de una persona, o null si no
     * procede.
     *
     * Vive aquí y NO en cada controlador porque la regla es una sola y ya la
     * tuvimos duplicada a medias: `AlumnoController` llamaba a un método
     * privado de `DocenteController`, así que abrir la ficha de un alumno
     * reventaba con «Call to undefined method». Un fallo así no lo atrapa una
     * suite de datos —es de composición— y solo aparece al entrar a la
     * pantalla.
     *
     * Devuelve null cuando quien mira no tiene el permiso, cuando la persona no
     * tiene cuenta, o cuando su cuenta no tiene rol activo: sin rol activo no
     * habría nada que ver del otro lado.
     *
     * @return array{usuario_id: int, usuario: string}|null
     */
    public function datosPara(Request $request, ?Persona $persona): ?array
    {
        if ($persona === null || ! $request->user()?->can('suplantar-usuarios')) {
            return null;
        }

        $cuenta = $persona->usuario;

        return $cuenta === null || $cuenta->rol_activo_id === null
            ? null
            : ['usuario_id' => $cuenta->id, 'usuario' => $cuenta->usuario];
    }

    public function iniciar(Request $request, Usuario $objetivo): void
    {
        /** @var Usuario $actual */
        $actual = $request->user();

        $this->validar($request, $actual, $objetivo);

        $this->registrar($request, 'suplantacion_inicio', $actual, $objetivo);

        // El id real se guarda ANTES de cambiar de usuario: después ya no habría
        // de dónde sacarlo.
        $request->session()->put(self::CLAVE_SESION, $actual->id);

        Auth::login($objetivo);
    }

    /**
     * Vuelve a ser uno mismo. No pide permisos: quien está suplantando tiene
     * los del suplantado, y exigirle algo para salir podría dejarlo atrapado.
     */
    public function terminar(Request $request): ?Usuario
    {
        $idReal = $request->session()->pull(self::CLAVE_SESION);

        if ($idReal === null) {
            return null;
        }

        $real = Usuario::find($idReal);

        if ($real === null) {
            // La cuenta real desapareció a media suplantación: se cierra sesión
            // en vez de dejar a alguien dentro con la identidad de otro.
            Auth::logout();

            return null;
        }

        $suplantado = $request->user();

        Auth::login($real);

        $this->registrar($request, 'suplantacion_fin', $real, $suplantado);

        return $real;
    }

    /** ¿La sesión actual es una suplantación? */
    public function estaSuplantando(Request $request): bool
    {
        return $request->session()->has(self::CLAVE_SESION);
    }

    /** El usuario real detrás de la suplantación. */
    public function usuarioReal(Request $request): ?Usuario
    {
        $id = $request->session()->get(self::CLAVE_SESION);

        return $id === null ? null : Usuario::find($id);
    }

    /**
     * Motivos por los que NO se puede suplantar a alguien. Vacío = adelante.
     *
     * @return array<int, string>
     */
    public function impedimentos(Request $request, Usuario $actual, Usuario $objetivo): array
    {
        $impedimentos = [];

        if ($this->estaSuplantando($request)) {
            $impedimentos[] = 'Ya estás suplantando a alguien; vuelve a tu cuenta primero.';
        }

        if ($actual->id === $objetivo->id) {
            $impedimentos[] = 'Esa es tu propia cuenta.';
        }

        // Sin esto, suplantar a un director sería la forma de obtener sus
        // permisos sin que nadie te los diera.
        if ($objetivo->tienePermiso('suplantar-usuarios')) {
            $impedimentos[] = 'No se puede suplantar a alguien que también puede suplantar.';
        }

        if ($objetivo->rol_activo_id === null) {
            $impedimentos[] = 'Esa cuenta no tiene un rol activo: no habría nada que ver.';
        }

        return $impedimentos;
    }

    private function validar(Request $request, Usuario $actual, Usuario $objetivo): void
    {
        $impedimentos = $this->impedimentos($request, $actual, $objetivo);

        if ($impedimentos !== []) {
            throw new RuntimeException(implode(' ', $impedimentos));
        }
    }

    /**
     * La bitácora cuelga del usuario SUPLANTADO: la pregunta que se hace
     * después es "¿quién entró como esta persona?", no al revés.
     */
    private function registrar(Request $request, string $evento, Usuario $real, ?Usuario $objetivo): void
    {
        Auditoria::create([
            'auditable_type' => Usuario::class,
            'auditable_id' => $objetivo?->id ?? $real->id,
            'evento' => $evento,
            'valores_anteriores' => null,
            'valores_nuevos' => [
                'suplantador_id' => $real->id,
                'suplantador' => $real->usuario,
                'suplantado_id' => $objetivo?->id,
                'suplantado' => $objetivo?->usuario,
            ],
            'usuario_id' => $real->id,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
