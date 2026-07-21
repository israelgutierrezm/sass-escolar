<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props que Inertia comparte con TODAS las páginas.
 *
 * Aquí se expone el contexto de sesión que el front necesita para pintar el
 * conmutador de rol y decidir qué menús mostrar: el usuario, su rol activo, los
 * roles entre los que puede conmutar (con su alcance por campus) y los permisos
 * EFECTIVOS del rol activo (propios + heredados de la jerarquía de roles).
 *
 * Los permisos que se envían son solo los del rol activo: el front no debe
 * conocer lo que podría hacer con otro rol. La autorización real se sigue
 * validando en el servidor.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'usuario' => $usuario === null ? null : $this->datosUsuario($usuario),
            ],

            'escuela' => tenant() === null ? null : [
                'id' => tenant('id'),
                'nombre' => tenant('id'),
            ],

            'flash' => [
                'exito' => fn () => $request->session()->get('exito'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosUsuario(Usuario $usuario): array
    {
        $usuario->loadMissing(['persona', 'rolActivo']);

        return [
            'id' => $usuario->id,
            'usuario' => $usuario->usuario,
            'email' => $usuario->email,
            'nombre_completo' => $usuario->persona?->nombreCompleto() ?? $usuario->usuario,
            'rol_activo' => $usuario->rolActivo === null ? null : [
                'id' => $usuario->rolActivo->id,
                'clave' => $usuario->rolActivo->name,
                'nombre' => $usuario->rolActivo->nombre,
            ],
            'roles_disponibles' => $this->rolesDisponibles($usuario),
            'permisos' => $usuario->rolActivo?->permisosEfectivos()->pluck('name')->sort()->values()->all() ?? [],
        ];
    }

    /**
     * Roles activos de la persona, con el campus al que se acotan (si aplica).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rolesDisponibles(Usuario $usuario): array
    {
        return PersonaRol::query()
            ->with(['rol', 'campus'])
            ->where('persona_id', $usuario->persona_id)
            ->where('activo', true)
            ->get()
            ->map(fn (PersonaRol $asignacion) => [
                'id' => $asignacion->rol->id,
                'clave' => $asignacion->rol->name,
                'nombre' => $asignacion->rol->nombre,
                'campus_id' => $asignacion->campus_id,
                'campus_nombre' => $asignacion->campus?->nombre,
            ])
            ->all();
    }
}
