<?php

declare(strict_types=1);

namespace App\Panel;

use App\Models\Identidad\TarjetaRol;
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

        // Tarjetas ENCENDIDAS para el rol activo. Null = sin configuración, o sea
        // se muestran todas las permitidas (default). Apagar es cosmético: NO
        // sustituye al permiso, que sigue filtrando primero.
        $activas = $usuario->rol_activo_id === null
            ? null
            : TarjetaRol::query()->where('rol_id', $usuario->rol_activo_id)->value('activas');

        foreach ($this->tarjetas as $clase) {
            /** @var TarjetaPanel $tarjeta */
            $tarjeta = app($clase);

            $permiso = $tarjeta->permiso();

            if ($permiso !== null && ! $usuario->can($permiso)) {
                continue;
            }

            if (is_array($activas) && ! in_array($tarjeta->clave(), $activas, true)) {
                continue; // el rol la tiene apagada
            }

            $datos = $tarjeta->datos($usuario);

            if ($datos === null) {
                continue;
            }

            $visibles[] = [
                'clave' => $tarjeta->clave(),
                'titulo' => $tarjeta->titulo(),
                'tipo' => $tarjeta->tipo(),
                'icono' => $tarjeta->icono(),
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

    /**
     * El catálogo de tarjetas (sin datos, sin usuario) para el editor por rol:
     * qué existe, su título, icono y el permiso que exige.
     *
     * @return array<int, array{clave: string, titulo: string, icono: string, permiso: ?string}>
     */
    public function catalogo(): array
    {
        return array_map(function (string $clase) {
            /** @var TarjetaPanel $tarjeta */
            $tarjeta = app($clase);

            return [
                'clave' => $tarjeta->clave(),
                'titulo' => $tarjeta->titulo(),
                'icono' => $tarjeta->icono(),
                'permiso' => $tarjeta->permiso(),
            ];
        }, $this->tarjetas);
    }
}
