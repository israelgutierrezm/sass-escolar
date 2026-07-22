<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\EmbudoAdmision;

/**
 * A quién le toca llamada hoy. Es la tarjeta que convierte el panel en una
 * lista de trabajo y no en un adorno.
 */
class ProspectosPorContactar implements TarjetaPanel
{
    public function __construct(private readonly EmbudoAdmision $embudo) {}

    public function clave(): string
    {
        return 'por-contactar';
    }

    public function titulo(): string
    {
        return 'Contactar hoy';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-prospectos';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function datos(Usuario $usuario): ?array
    {
        $pendientes = $this->embudo->pendientesDeContacto($usuario);

        // Sin pendientes NO se muestra vacía: una tarjeta que dice "nada" todos
        // los días enseña a ignorarla, y el día que sí tenga algo tampoco se
        // mirará.
        if ($pendientes === []) {
            return null;
        }

        return [
            'renglones' => array_map(fn (array $p) => [
                'etiqueta' => $p['nombre'] ?? 'Prospecto',
                'detalle' => $p['etapa'],
                'valor' => $p['dias'] > 0 ? $p['dias'].' d de retraso' : 'hoy',
                'alerta' => $p['dias'] > 0,
                'enlace' => '/aspirantes/'.$p['id'],
            ], array_slice($pendientes, 0, 8)),
            'pie' => count($pendientes).' en total',
        ];
    }
}
