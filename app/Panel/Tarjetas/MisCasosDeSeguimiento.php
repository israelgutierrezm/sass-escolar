<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Models\Permanencia\CasoPermanencia;
use App\Panel\TarjetaDeModulo;
use App\Panel\TarjetaPanel;

/**
 * Los casos que ESTA persona lleva, con lo que se le está pasando arriba.
 *
 * ── Los SUYOS y no los de la escuela ──────────────────────────────────────
 * Un panel es lo que a uno le toca hoy. La cifra de la escuela vive en el
 * tablero, con su permiso propio; aquí lo que sirve es «tengo tres, y a uno de
 * ellos ya se me pasó el plazo».
 *
 * ── Lo VENCIDO primero, y es la mitad de la tarjeta ───────────────────────
 * Un caso abierto sobre alguien con quien nadie ha hablado no acompaña a nadie:
 * es una carpeta. Ordenado por prioridad, lo que lleva tres semanas parado no se
 * mira nunca.
 *
 * ── Vacía se calla ────────────────────────────────────────────────────────
 * Sin casos propios no hay nada que hacer, y una tarjeta en cero ocupa el sitio
 * de otra que sí pide trabajo. Es la regla de vacíos del proyecto.
 */
class MisCasosDeSeguimiento implements TarjetaDeModulo, TarjetaPanel
{
    private const A_LA_VISTA = 5;

    public function modulo(): string
    {
        return 'permanencia';
    }

    public function clave(): string
    {
        return 'mis-casos-de-seguimiento';
    }

    public function titulo(): string
    {
        return 'Mis casos de seguimiento';
    }

    public function permiso(): ?string
    {
        return 'registrar-intervenciones';
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
        return 'M15.182 15.182a4.5 4.5 0 0 1-6.364 0M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75Zm-.375 0h.008v.015h-.008V9.75Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $mios = CasoPermanencia::query()
            ->abiertos()
            ->where('responsable_id', $usuario->id)
            ->with(['matricula:id,persona_id,matricula', 'matricula.persona:id,nombre,primer_apellido,segundo_apellido'])
            ->get();

        if ($mios->isEmpty()) {
            return null;
        }

        $vencidos = $mios->filter(fn (CasoPermanencia $c) => $c->slaVencido());

        $renglones = $mios
            /*
             * Lo vencido arriba, y dentro lo más viejo. Es el mismo criterio de
             * la bandeja: por prioridad, lo que lleva tres semanas parado no se
             * mira nunca.
             */
            ->sortBy(fn (CasoPermanencia $c) => [$c->slaVencido() ? 0 : 1, $c->abierto_en->timestamp])
            ->take(self::A_LA_VISTA)
            ->map(fn (CasoPermanencia $c) => [
                'etiqueta' => $c->matricula?->persona?->nombreCompleto() ?? 'Sin alumno',
                'detalle' => $c->folio.' · '.$c->estado->etiqueta(),
                'valor' => $c->primer_contacto_en !== null
                    ? 'Contactado'
                    : ($c->slaVencido() ? 'Fuera de plazo' : 'Sin contacto aún'),
                'pie' => null,
                'progreso' => null,
                'alerta' => $c->slaVencido(),
                'enlace' => "/permanencia/casos/{$c->id}",
            ])
            ->values()
            ->all();

        return [
            'renglones' => $renglones,
            'pie' => $vencidos->isEmpty()
                ? ($mios->count() === 1 ? '1 caso abierto' : "{$mios->count()} casos abiertos")
                : "{$vencidos->count()} de {$mios->count()} fuera del plazo de primer contacto",
            'enlace' => '/permanencia/casos',
        ];
    }
}
