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

    public function icono(): string
    {
        return 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z';
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
