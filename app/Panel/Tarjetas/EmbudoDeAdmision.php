<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel as Contrato;
use App\Services\EmbudoAdmision;

/**
 * El embudo, con el mismo alcance que la pantalla de captación: el promotor ve
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

    /**
     * Tipo propio y no `barras`.
     *
     * Las dos dibujan una barra por renglón, pero dicen cosas distintas. En
     * `barras` cada renglón es independiente —el avance de una materia no tiene
     * nada que ver con el de otra— y el valor ya viene en porcentaje. Aquí los
     * renglones son las etapas de UN mismo recorrido y sus totales suman el
     * embudo entero, así que la barra tiene que medirse contra ese total y las
     * etapas conservar su orden. Dibujarlas con la misma plantilla obligaba a
     * elegir una de las dos lecturas y mentir en la otra.
     */
    public function tipo(): string
    {
        return 'embudo';
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
                // Qué parte del embudo está parada en esta etapa.
                //
                // Se manda calculada desde aquí, y contra el TOTAL, porque es
                // el dato y no el dibujo. Medir la barra contra la etapa más
                // poblada —que es lo que hacía antes— sólo dice quién es el
                // más grande: con 90 en el primer paso y 3 en el último, la
                // barra del 90 llenaba el ancho igual que si fueran 9, y el
                // 3 se veía idéntico llevara detrás cien prospectos o diez.
                'parte' => (int) round($e['total'] * 100 / $total),
                'enlace' => '/captacion/etapas/'.$e['id'],
            ], $etapas),
            'pie' => $total.' prospectos',
            'enlace' => '/captacion',
        ];
    }
}
