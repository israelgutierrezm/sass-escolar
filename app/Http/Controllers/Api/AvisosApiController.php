<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Services\Plataforma\AvisosDeUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los avisos que le llegan a quien entró, para la app móvil.
 *
 * ── Fuera del grupo de faceta, a propósito ─────────────────────────────────
 * Recibir un aviso no es una facultad que se otorgue ni depende del rol activo:
 * `AvisosDeUsuario` resuelve los destinos contra la PERSONA y sus roles
 * disponibles, no contra la faceta con la que se opera. Por eso este endpoint
 * vive bajo `auth:sanctum` a secas y sirve igual al alumno, a la familia o al
 * docente cuando lleguen sus portales.
 *
 * Es el mismo servicio que la pantalla web `MisAvisos`: una sola verdad.
 */
class AvisosApiController extends Controller
{
    public function __construct(private readonly AvisosDeUsuario $avisos) {}

    /** Todo lo vigente que le llega, con el contador de lo que le falta atender. */
    public function index(Request $peticion): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        return response()->json([
            'avisos' => $this->avisos->todos($usuario),
            'sin_leer' => $this->avisos->sinLeer($usuario),
        ]);
    }

    /**
     * «Lo leí»: quita de en medio un crítico y apaga un importante.
     *
     * 404 y no 403 si no le tocaba: un 403 confirmaría que el aviso existe.
     */
    public function confirmar(Request $peticion, Aviso $aviso): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        abort_unless($this->avisos->confirmar($usuario, $aviso), 404);

        return response()->json(['ok' => true]);
    }
}
