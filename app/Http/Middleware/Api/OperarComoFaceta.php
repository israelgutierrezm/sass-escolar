<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la FACETA con la que opera la app en esta petición de la API.
 *
 * ── Por qué existe (y por qué no basta `EstablecerRolActivo`) ──────────────
 * El `Gate::before` resuelve los `can:` contra el ROL ACTIVO del usuario
 * (`rol_activo_id`), y de qué es dueña cada cartera lo decide
 * `VeLaCarteraDelAlumno` mirando el ÁMBITO de ese rol. En la web,
 * `EstablecerRolActivo` mantiene ese valor al día en cada request; pero corre
 * en el grupo `web` y sobre `Auth::user()` (guard de SESIÓN), así que la API
 * —que autentica por TOKEN con el guard `sanctum`, en un grupo aparte SIN
 * `web`— se quedaba sin él. Un alumno que sólo use la app y nunca haya entrado
 * a la web tiene `rol_activo_id` en null, y con él TODO `can:` falla cerrado
 * (403). Esto lo resuelve para la API.
 *
 * ── Fija la faceta del PORTAL, no «el primer rol válido» ────────────────────
 * La app entra por FACETA (el portal del alumno, el de la familia…), así que
 * aquí se pide EXACTAMENTE esa faceta. Con «el primer rol válido» —lo que hace
 * la web— alguien que además de alumno fuera administrativo abriría su «estado
 * de cuenta» del portal y vería la cartera de TODA la escuela, porque
 * `ambitoDePermisos()` del rol administrativo es `escuela`. Pinchar la faceta
 * del portal es lo que hace que el portal del alumno opere SIEMPRE como alumno.
 *
 * ── En MEMORIA, sin persistir ──────────────────────────────────────────────
 * No se guarda `rol_activo_id`: el rol activo de la web es la elección
 * explícita de la persona (con su propio conmutador y su propia persistencia),
 * y que abrir la app le cambiara en silencio lo que ve en la web sería un
 * efecto secundario que nadie pidió. Se fija sólo para esta petición, y se le
 * inyecta la relación ya resuelta para que el Gate no consulte de más.
 *
 * ── Quien no tiene la faceta, 403 ──────────────────────────────────────────
 * Un administrativo puro que le pegue al portal del alumno no tiene rol de esa
 * faceta: se rehúsa. La app no llega ahí —enruta por faceta— pero el servidor
 * no confía en la app.
 */
class OperarComoFaceta
{
    public function handle(Request $request, Closure $next, string $faceta): Response
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();

        $rol = $usuario?->rolesDisponibles()
            ->first(fn (Rol $r) => $r->faceta()->name === $faceta);

        abort_if($rol === null, 403, 'Tu cuenta no tiene el perfil requerido en esta escuela.');

        $usuario->rol_activo_id = $rol->id;
        $usuario->setRelation('rolActivo', $rol);

        return $next($request);
    }
}
