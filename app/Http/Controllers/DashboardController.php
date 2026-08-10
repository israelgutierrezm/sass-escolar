<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Efemeride;
use App\Panel\RegistroTarjetas;
use App\Services\Plataforma\AgendaDelPanel;
use App\Services\Plataforma\LatidoDelDespachador;
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
        private readonly LatidoDelDespachador $latidos,
    ) {}

    /**
     * El aviso, o null si no hay nada que avisar a ESTA persona.
     *
     * Se comprueba en cada carga del panel y no con una tarea programada, por
     * una razón elemental: si el despachador está caído, una tarea programada
     * que avise de que el despachador está caído tampoco correría.
     *
     * @return array<string, mixed>|null
     */
    private function avisoDelDespachador(Usuario $usuario): ?array
    {
        // `ver-configuracion` y no un permiso nuevo: quien administra los
        // parámetros de la escuela es quien llama a soporte cuando algo del
        // servidor deja de funcionar.
        if (! $usuario->can('ver-configuracion')) {
            return null;
        }

        $estado = $this->latidos->estado();

        return $estado['vivo'] ? null : $estado;
    }

    public function __invoke(): Response
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $mes = date('Y-m');

        return Inertia::render('Dashboard', [
            'tarjetas' => $this->registro->para($usuario),
            'campusDelRol' => $usuario->campusDelRolActivo(),

            /*
             * Aviso de que el despachador dejó de correr.
             *
             * Sólo para quien puede hacer algo al respecto: es un problema del
             * SERVIDOR, y a un docente o a un alumno decirle que «el cron está
             * caído» le da una alarma que no puede atender. `null` cuando todo
             * va bien o cuando quien mira no es de plataforma, así que la
             * pantalla no tiene que decidir nada.
             */
            'despachador' => $this->avisoDelDespachador($usuario),

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

                /*
                 * Qué se conmemora hoy. Va con la agenda y no en tarjeta
                 * aparte: es contexto del día, del mismo tipo que la fecha y el
                 * clima. Vacío casi todos los días —y entonces no se pinta—.
                 */
                'efemerides' => Efemeride::query()
                    ->delDia((int) date('n'), (int) date('j'))
                    ->orderBy('tipo')
                    ->get()
                    ->map(fn (Efemeride $e) => [
                        'titulo' => $e->titulo,
                        'descripcion' => $e->descripcion,
                        'color' => $e->color(),
                        'aniversario' => $e->aniversario(),
                    ])
                    ->values(),
            ],
        ]);
    }
}
