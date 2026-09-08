<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Http\Controllers\Controller;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\OrganizacionContacto;
use App\Services\ProcesosFormativos\AccesoSupervisorExterno;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quién administra el ACCESO de los supervisores externos al portal.
 *
 * ── Institucional, no por campus ────────────────────────────────────────────
 * El padrón de organizaciones y sus contactos es institucional —lo dice el
 * propio módulo—, así que esta pantalla no se acota por campus. Lo que SÍ está
 * acotado es lo que el supervisor ve una vez dentro: sus expedientes asignados,
 * y de eso se encarga {@see \App\Services\ProcesosFormativos\AlcanceDeExpedientes}.
 *
 * ── El acto irreversible-en-la-práctica se confirma en la pantalla ──────────
 * Invitar crea una cuenta y una contraseña temporal; revocar corta el acceso.
 * Los dos son actos deliberados con su bitácora (auditoría del contacto). La
 * contraseña temporal se muestra UNA vez, en el flash: no se guarda en claro ni
 * se manda por un canal que no controlamos.
 */
class SupervisorExternoController extends Controller
{
    public function __construct(private readonly AccesoSupervisorExterno $acceso) {}

    public function index(): Response
    {
        $contactos = OrganizacionContacto::query()
            ->where('es_supervisor', true)
            ->with([
                'organizacion:id,razon_social,nombre_comercial',
                'persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->withCount('expedientesSupervisados')
            ->orderBy('nombre')
            ->get()
            ->map(fn (OrganizacionContacto $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'cargo' => $c->cargo,
                'correo' => $c->correo,
                'organizacion' => $c->organizacion?->comoSeLeConoce(),
                'expedientes' => $c->expedientes_supervisados_count,
                'estado' => $c->estadoAcceso(),
                'acceso_desde' => $c->acceso_desde?->toDateString(),
                'acceso_hasta' => $c->acceso_hasta?->toDateString(),
                'invitado_en' => $c->invitado_en?->format('d/m/Y H:i'),
                'tiene_cuenta' => $c->persona_id !== null,
            ])
            ->values();

        return Inertia::render('Procesos/Supervisores', [
            'contactos' => $contactos,
        ]);
    }

    public function invitar(Request $peticion, OrganizacionContacto $contacto): RedirectResponse
    {
        $datos = $peticion->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        /** @var Usuario $quien */
        $quien = $peticion->user();

        $resultado = $this->acceso->invitar($contacto, [
            'desde' => $datos['desde'] ?? null,
            'hasta' => $datos['hasta'] ?? null,
        ], $quien);

        $mensaje = $resultado['password'] === null
            ? 'Acceso concedido. Esta persona ya tenía cuenta y entra con su contraseña de siempre.'
            : 'Acceso concedido. Contraseña temporal (se muestra una sola vez): '.$resultado['password'];

        return back(303)->with('exito', $mensaje);
    }

    public function revocar(Request $peticion, OrganizacionContacto $contacto): RedirectResponse
    {
        /** @var Usuario $quien */
        $quien = $peticion->user();

        $this->acceso->revocar($contacto, $quien);

        return back(303)->with('exito', 'Acceso revocado. Esta persona ya no entra al portal de supervisión.');
    }
}
