<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Bolsa\EtapaPostulacion;
use App\Models\Bolsa\Postulacion;
use App\Models\Bolsa\Vacante;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Los postulantes que están esperando a que vinculación haga algo.
 *
 * ── Es una COLA, no un tablero ────────────────────────────────────────────
 * Cuenta sólo las postulaciones en una etapa que NO cierra el proceso: quien ya
 * quedó contratado, rechazado o desistió no espera nada de nadie. Sin ese
 * filtro la cifra sólo subiría, y una cola que nunca baja enseña a ignorarla.
 *
 * Qué etapa cierra lo dice `etapas_postulacion.es_final`, no una lista de claves
 * aquí: la escuela puede renombrar sus etapas o agregar las suyas.
 *
 * ── Vacía se calla ────────────────────────────────────────────────────────
 * Sin nadie esperando no hay nada que hacer, y una tarjeta en cero ocupa el
 * sitio de otra que sí pide trabajo.
 */
class PostulantesEnProceso implements TarjetaPanel
{
    private const A_LA_VISTA = 5;

    public function clave(): string
    {
        return 'postulantes-en-proceso';
    }

    public function titulo(): string
    {
        return 'Postulantes en proceso';
    }

    public function permiso(): ?string
    {
        return 'gestionar-bolsa-trabajo';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $finales = EtapaPostulacion::query()->finales()->pluck('id');

        $porVacante = Postulacion::query()
            ->selectRaw('vacante_id, count(*) as total')
            ->whereNotIn('etapa_id', $finales)
            ->groupBy('vacante_id')
            ->orderByDesc('total')
            ->limit(self::A_LA_VISTA)
            ->get();

        if ($porVacante->isEmpty()) {
            return null;
        }

        $vacantes = Vacante::query()
            ->whereIn('id', $porVacante->pluck('vacante_id'))
            ->with('empresa:id,razon_social')
            ->get(['id', 'titulo', 'empresa_id'])
            ->keyBy('id');

        $total = Postulacion::query()->whereNotIn('etapa_id', $finales)->count();

        return [
            'renglones' => $porVacante->map(function ($fila) use ($vacantes) {
                $vacante = $vacantes->get($fila->vacante_id);

                return [
                    'etiqueta' => $vacante?->titulo ?? 'Ya no existe',
                    'detalle' => $vacante?->empresa?->razon_social,
                    'valor' => $fila->total === 1 ? '1 persona' : "{$fila->total} personas",
                    'pie' => null,
                    'progreso' => null,
                    'alerta' => false,
                    'enlace' => $vacante === null ? null : "/bolsa/vacantes/{$vacante->id}/postulaciones",
                ];
            })->all(),
            'pie' => $total === 1 ? '1 postulante esperando' : "{$total} postulantes esperando",
            'enlace' => '/bolsa/vacantes',
        ];
    }
}
