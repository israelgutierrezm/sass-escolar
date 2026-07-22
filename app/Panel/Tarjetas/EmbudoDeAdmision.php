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
