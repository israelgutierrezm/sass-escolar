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
                /*
                 * El ancho puede depender de CUÁNTO trajo la tarjeta.
                 *
                 * «Accesos directos» declara 4 columnas porque puede traer
                 * doce, pero a un alumno le salen dos: ocupaba el ancho
                 * completo del panel para mostrar un botón, con el resto en
                 * blanco. Una tarjeta que sabe cuánto contenido tiene puede
                 * pedir un ancho menor devolviendo `ancho_sugerido` en sus
                 * datos; sin eso, manda el declarado.
                 */
                'ancho' => $this->anchoQueCierraLaFila((int) ($datos['ancho_sugerido'] ?? $tarjeta->ancho())),
                'datos' => $datos,
            ];
        }

        return $visibles;
    }

    /**
     * El ancho de una tarjeta, redondeado para que la fila SIEMPRE cierre.
     *
     * ── Por qué no se respeta el ancho tal cual ────────────────────────────
     * El panel tiene cuatro columnas. Con anchos impares una fila puede sumar
     * tres —una tarjeta de 1 junto a una de 2— y la cuarta columna se queda en
     * blanco: un hueco a la derecha que se lee como algo roto, no como diseño.
     * Y `grid-flow-dense` sólo lo tapa si más adelante viene otra tarjeta que
     * quepa justo ahí, cosa que depende del rol.
     *
     * Redondeando hacia arriba a un número PAR, toda combinación cierra: 2+2 o
     * 4. Como mucho queda media fila al final si el rol tiene un número impar
     * de tarjetas, y ése es un hueco al final, no en medio.
     *
     * Se hace aquí y no en cada tarjeta porque las tarjetas siguen declarando
     * lo que necesitan —«Accesos directos» pide menos cuando trae dos botones—
     * y es el panel quien sabe cuántas columnas tiene.
     */
    private function anchoQueCierraLaFila(int $pedido): int
    {
        $acotado = max(1, min(4, $pedido));

        return $acotado <= 2 ? 2 : 4;
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
