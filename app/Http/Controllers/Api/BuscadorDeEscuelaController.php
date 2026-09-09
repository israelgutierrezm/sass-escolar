<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * La PRIMERA pantalla de la app móvil: traducir un CÓDIGO de escuela a su
 * dominio, para que el cliente sepa a quién hablarle ANTES de que nadie inicie
 * sesión.
 *
 * Vive en el dominio CENTRAL —es lo único que la app puede preguntar sin saber
 * todavía a qué tenant pertenece—. Es público (corre antes del acceso) y por
 * código EXACTO: no lista escuelas ni deja enumerarlas, sólo confirma la que ya
 * se conoce. El login, con sus credenciales, ocurre después contra el dominio
 * que esto devuelve.
 */
class BuscadorDeEscuelaController extends Controller
{
    public function mostrar(string $codigo): JsonResponse
    {
        $escuela = Tenant::query()->with('domains')->find(trim($codigo));
        $dominio = $escuela?->domains->first()?->domain;

        if ($escuela === null || $dominio === null) {
            return response()->json(['mensaje' => 'No encontramos una escuela con ese código.'], 404);
        }

        return response()->json([
            'codigo' => $escuela->id,
            'nombre' => $escuela->nombre ?? $escuela->id,
            'dominio' => $dominio,
        ]);
    }
}
