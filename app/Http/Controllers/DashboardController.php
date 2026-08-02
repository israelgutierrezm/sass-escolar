<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use App\Panel\RegistroTarjetas;
use App\Services\Plataforma\AgendaDelPanel;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El panel de la escuela.
 *
 * **No sabe de roles.** Le pide al `RegistroTarjetas` las tarjetas que este
 * usuario puede ver y las entrega tal cual: cada tarjeta declara qué permiso
 * exige y si aplica a esta persona. Un rol nuevo armado desde
 * `/plataforma/roles` obtiene su panel solo — que es el punto del pedido del
 * cliente: se implementa el mecanismo, no sus ejemplos.
 *
 * Se conserva el bloque de contexto de sesión (quién eres, con qué rol operas,
 * qué te permite) porque es lo que hace evidente por qué ves lo que ves, y es
 * lo primero que se consulta cuando alguien reclama un 403.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly RegistroTarjetas $registro,
        private readonly AgendaDelPanel $agenda,
    ) {}

    public function __invoke(): Response
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $mes = date('Y-m');

        return Inertia::render('Dashboard', [
            'tarjetas' => $this->registro->para($usuario),
            'campusDelRol' => $usuario->campusDelRolActivo(),

            /*
             * La agenda va a la DERECHA de todos los paneles, y es la misma
             * para cualquier rol: lo que viene, mezclando el calendario de la
             * escuela con lo que vence de sus materias. Cada quien ve lo suyo
             * porque el servicio ya filtró por pertenencia.
             */
            'agenda' => [
                'mes' => $mes,
                'proximos' => $this->agenda->proximos($usuario),
                'marcados' => $this->agenda->diasMarcados($usuario, $mes),
                'hoy' => date('Y-m-d'),
            ],
        ]);
    }
}
