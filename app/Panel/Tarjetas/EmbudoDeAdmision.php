<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel as Contrato;
use App\Services\EmbudoAdmision;

/**
 * El embudo, con el mismo alcance que la pantalla de promoción: el promotor ve
 * los suyos, quien coordina los ve todos. La tarjeta no reimplementa el
 * acotamiento — se lo pide al servicio, para que no puedan divergir.
 */
class EmbudoDeAdmision implements Contrato
{
    public function __construct(private readonly EmbudoAdmision $embudo) {}

    public function clave(): string
    {
        return 'embudo';
    }

    public function titulo(): string
    {
        return 'Embudo de admisión';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-prospectos';
    }

    public function tipo(): string
    {
        return 'barras';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $etapas = $this->embudo->porEtapa($usuario);
        $total = array_sum(array_column($etapas, 'total'));

        if ($total === 0) {
            return null;
        }

        return [
            'series' => array_map(fn (array $e) => [
                'etiqueta' => $e['nombre'],
                'valor' => $e['total'],
                'enlace' => '/promocion/etapas/'.$e['id'],
            ], $etapas),
            'pie' => $total.' prospectos',
            'enlace' => '/promocion',
        ];
    }
}
