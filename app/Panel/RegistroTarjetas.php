<?php

declare(strict_types=1);

namespace App\Panel;

use App\Models\Identidad\Usuario;

/**
 * El catálogo de tarjetas del panel.
 *
 * Se registra en `AppServiceProvider`. El controlador no conoce ninguna tarjeta
 * concreta: pide las que el usuario puede ver y las entrega tal cual.
 *
 * Una tarjeta se descarta por dos motivos distintos y ambos importan:
 *  - no tiene el permiso (no le toca verla), o
 *  - lo tiene pero la tarjeta devolvió null (le toca, pero no aplica a él).
 * El segundo caso es el que evita que un administrativo con `ver-kardex` vea un
 * "mi avance" vacío por no ser alumno de nada.
 */
class RegistroTarjetas
{
    /** @var array<int, class-string<TarjetaPanel>> */
    private array $tarjetas = [];

    /**
     * @param  class-string<TarjetaPanel>  $tarjeta
     */
    public function registrar(string $tarjeta): void
    {
        $this->tarjetas[] = $tarjeta;
    }

    /**
     * Las tarjetas que este usuario ve, ya resueltas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function para(Usuario $usuario): array
    {
        $visibles = [];

        foreach ($this->tarjetas as $clase) {
            /** @var TarjetaPanel $tarjeta */
            $tarjeta = app($clase);

            $permiso = $tarjeta->permiso();

            if ($permiso !== null && ! $usuario->can($permiso)) {
                continue;
            }

            $datos = $tarjeta->datos($usuario);

            if ($datos === null) {
                continue;
            }

            $visibles[] = [
                'clave' => $tarjeta->clave(),
                'titulo' => $tarjeta->titulo(),
                'tipo' => $tarjeta->tipo(),
                'ancho' => max(1, min(4, $tarjeta->ancho())),
                'datos' => $datos,
            ];
        }

        return $visibles;
    }

    /** @return array<int, class-string<TarjetaPanel>> */
    public function registradas(): array
    {
        return $this->tarjetas;
    }
}
