<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;

/**
 * Pasos comunes al ENTRAR a una escuela, sea por contraseña o por SSO de Google.
 *
 * Autenticar (verificar credenciales o el correo de Google) es lo que cambia;
 * lo de después —asegurar un rol activo, marcar la cuenta conectada y asentar
 * la entrada en la bitácora— es idéntico y vive aquí para no divergir.
 */
class IniciadorSesion
{
    public function __construct(private readonly BitacoraAccesos $bitacora) {}

    /**
     * Cierra el acceso de un usuario YA autenticado (Auth::login ya ocurrió).
     *
     * @param  array<string, mixed>  $detalle  cómo entró, cuando no fue por la
     *                                         puerta de siempre. Lo usa el
     *                                         regreso con «recuérdame», que no
     *                                         cruza el controlador de login.
     */
    public function finalizar(Usuario $usuario, Request $request, array $detalle = []): void
    {
        $this->asegurarRolActivo($usuario);

        $usuario->forceFill(['conectado' => true])->save();

        $this->bitacora->entrada($usuario, $request, $detalle);
    }

    /**
     * Si el usuario no trae un rol activo válido, se le asigna el primero
     * disponible. Sin rol activo no vería nada.
     */
    private function asegurarRolActivo(Usuario $usuario): void
    {
        if ($usuario->rol_activo_id !== null && $usuario->puedeUsarRol($usuario->rol_activo_id)) {
            return;
        }

        $primerRol = PersonaRol::query()
            ->where('persona_id', $usuario->persona_id)
            ->where('activo', true)
            ->value('rol_id');

        $usuario->forceFill(['rol_activo_id' => $primerRol])->save();
    }
}
