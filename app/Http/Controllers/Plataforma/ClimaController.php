<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Identidad\Usuario;
use App\Services\Plataforma\ClimaDelCampus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El clima del panel.
 *
 * ── Por qué un endpoint aparte y no un prop del panel ──────────────────────
 * Si viajara con la página, cada carga del panel esperaría a un servicio
 * gratuito de otro país antes de pintar una sola letra. Así el panel abre al
 * instante y la tarjeta aparece cuando llega —o no aparece, y no pasa nada—.
 *
 * Sin `can:`: es información pública del lugar donde uno estudia, y todos los
 * roles tienen panel. Lo único que hace falta es sesión.
 */
class ClimaController extends Controller
{
    public function __invoke(Request $request, ClimaDelCampus $clima): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        /*
         * Las coordenadas que el navegador entregó con permiso de la persona.
         *
         * Se validan aunque vengan de nuestro propio front: es una petición
         * HTTP como cualquiera, y un par de números fuera de rango llegaría tal
         * cual a la llave del cache y a la URL del servicio de clima.
         */
        $datos = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $coordenadas = isset($datos['lat'], $datos['lon'])
            ? ['latitud' => (float) $datos['lat'], 'longitud' => (float) $datos['lon']]
            : null;

        return response()->json($clima->para($usuario, $request->ip(), $coordenadas));
    }
}
