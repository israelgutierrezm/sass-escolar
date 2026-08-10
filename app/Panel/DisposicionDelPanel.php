<?php

declare(strict_types=1);

namespace App\Panel;

use App\Models\Identidad\DisposicionPanel;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * La disposición que cada persona le dio a su panel: en qué orden van sus
 * tarjetas y cuáles ocupan el ancho doble.
 *
 * Se aplica DESPUÉS de que el registro decidió qué tarjetas se ven. Esa
 * separación es la que hace que la preferencia nunca pueda mostrar de más:
 * acomodar es cosmético, igual que encender una tarjeta en `tarjetas_rol`, y
 * una clave guardada que hoy no pasa el permiso simplemente no encuentra pareja
 * y se ignora.
 */
class DisposicionDelPanel
{
    /** Los dos únicos anchos: el normal y el doble, sobre cuatro columnas. */
    public const ANCHOS = [2, 4];

    /**
     * Reordena y redimensiona las tarjetas visibles según lo que guardó esta
     * persona para el perfil con el que está operando.
     *
     * Las que no tienen preferencia se van al FINAL conservando entre ellas el
     * orden de registro. Es lo que hace que una tarjeta nueva —o una que
     * reaparece porque le dieron un permiso— salga sin desordenar lo que la
     * persona ya había acomodado: aparece abajo, se ve, y se mueve si estorba.
     *
     * @param  array<int, array<string, mixed>>  $tarjetas
     * @return array<int, array<string, mixed>>
     */
    public function aplicar(array $tarjetas, Usuario $usuario): array
    {
        $guardado = $this->guardadaPara($usuario);

        if ($guardado === []) {
            return $tarjetas;
        }

        $conOrden = [];

        foreach ($tarjetas as $posicion => $tarjeta) {
            $preferencia = $guardado[$tarjeta['clave']] ?? null;

            if ($preferencia !== null) {
                $tarjeta['ancho'] = $preferencia['ancho'];
            }

            $conOrden[] = [
                // Las que no tienen preferencia van detrás de TODAS las que sí:
                // el orden guardado nunca llega a PHP_INT_MAX.
                'orden' => $preferencia['orden'] ?? PHP_INT_MAX,
                // Desempate estable. Sin esto, dos tarjetas sin preferencia
                // quedan a merced de cómo ordene `usort`, que en PHP no
                // garantiza estabilidad para elementos equivalentes.
                'posicion' => $posicion,
                'tarjeta' => $tarjeta,
            ];
        }

        usort(
            $conOrden,
            fn (array $a, array $b) => [$a['orden'], $a['posicion']] <=> [$b['orden'], $b['posicion']],
        );

        return array_column($conOrden, 'tarjeta');
    }

    /**
     * Guarda la disposición completa del perfil activo.
     *
     * Se reemplaza entera y no se van actualizando filas una por una: la
     * disposición es una lista ordenada, y actualizar en sitio obliga a
     * resolver a mano las que ya no están. Borrar y escribir deja siempre
     * exactamente lo que la pantalla mandó.
     *
     * @param  array<int, array{clave: string, ancho: int}>  $disposicion
     */
    public function guardar(Usuario $usuario, array $disposicion): void
    {
        DB::transaction(function () use ($usuario, $disposicion) {
            $this->consulta($usuario)->delete();

            foreach (array_values($disposicion) as $orden => $tarjeta) {
                DisposicionPanel::create([
                    'usuario_id' => $usuario->id,
                    'rol_id' => $usuario->rol_activo_id,
                    'clave' => $tarjeta['clave'],
                    'orden' => $orden,
                    // Cualquier cosa que no sea el ancho doble es el normal: el
                    // servidor no confía en el número que llegó del navegador.
                    'ancho' => (int) $tarjeta['ancho'] === 4 ? 4 : 2,
                ]);
            }
        });
    }

    /** Deja el panel como viene de fábrica para este perfil. */
    public function olvidar(Usuario $usuario): void
    {
        $this->consulta($usuario)->delete();
    }

    /**
     * Lo guardado, indexado por clave de tarjeta.
     *
     * @return array<string, array{orden: int, ancho: int}>
     */
    private function guardadaPara(Usuario $usuario): array
    {
        return $this->consulta($usuario)
            ->get(['clave', 'orden', 'ancho'])
            ->keyBy('clave')
            ->map(fn (DisposicionPanel $d) => ['orden' => $d->orden, 'ancho' => $d->ancho])
            ->all();
    }

    /**
     * El perfil activo: esta persona con este rol.
     *
     * `where('rol_id', null)` se traduce a `is null`, que es justo lo que hace
     * falta para quien todavía no eligió perfil.
     */
    private function consulta(Usuario $usuario)
    {
        return DisposicionPanel::query()
            ->where('usuario_id', $usuario->id)
            ->where('rol_id', $usuario->rol_activo_id);
    }
}
