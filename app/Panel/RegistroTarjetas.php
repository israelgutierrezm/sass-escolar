<?php

declare(strict_types=1);

namespace App\Panel;

use App\Models\Identidad\TarjetaRol;
use App\Models\Identidad\Usuario;
use App\Services\Plataforma\ModulosDeLaEscuela;

/**
 * El catálogo de tarjetas del panel.
 *
 * Se registra en `AppServiceProvider`. El controlador no conoce ninguna tarjeta
 * concreta: pide las que el usuario puede ver y las entrega tal cual.
 *
 * Una tarjeta se descarta por TRES motivos distintos y los tres importan:
 *  - su MÓDULO está apagado (esa función no existe para esta escuela),
 *  - no tiene el permiso (no le toca verla), o
 *  - lo tiene pero la tarjeta devolvió null (le toca, pero no aplica a él).
 *
 * El segundo caso es el que evita que un administrativo con `ver-historial-academico` vea un
 * "mi avance" vacío por no ser alumno de nada.
 *
 * Y el PRIMERO faltaba: apagar `bolsa_trabajo` en `/plataforma/modulos` dejaba
 * «Postulantes en proceso» en el panel, con un enlace a `/bolsa/vacantes` que la
 * ruta sí comprueba — o sea que llevaba a un 404. Se comprobó sembrando una
 * postulación, porque el demo tiene la bolsa vacía a propósito y sin el caso la
 * tarjeta devuelve null en las dos direcciones.
 *
 * Se comprueba AQUÍ y no en cada tarjeta, que es la misma decisión que este
 * proyecto tomó para el menú lateral cuando le pasó lo mismo: si cada una se
 * comprueba sola, la que se olvide no falla — se pinta.
 */
class RegistroTarjetas
{
    /** @var array<int, class-string<TarjetaPanel>> */
    private array $tarjetas = [];

    public function __construct(private readonly ModulosDeLaEscuela $modulos) {}

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

            /*
             * El MÓDULO primero, porque es la más fuerte de las tres: un módulo
             * apagado significa que esa función no existe para esta escuela, así
             * que da igual quién mire.
             *
             * Sólo lo declaran las tarjetas cuya sección ya está gateada por
             * `modulo:`. Los módulos NÚCLEO figuran como apagados en el demo
             * —no tienen fila y `ModulosDeLaEscuela` falla cerrado—, así que
             * declarárselo a una tarjeta de finanzas la haría desaparecer.
             */
            if ($tarjeta instanceof TarjetaDeModulo && ! $this->modulos->activo($tarjeta->modulo())) {
                continue;
            }

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

        // Al final del todo, y en un servicio aparte: lo que esta persona
        // acomodó a mano. Va después de filtrar a propósito —acomodar no puede
        // hacer aparecer una tarjeta que el permiso no deja ver.
        return app(DisposicionDelPanel::class)->aplicar($visibles, $usuario);
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
