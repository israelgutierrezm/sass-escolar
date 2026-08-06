<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Un error cuyo motivo SÍ se le puede decir a quien lo provocó.
 *
 * Los `abort(403, '...')` repartidos por los controladores traen explicaciones
 * buenas —«Este alumno no está vinculado a tu cuenta»— que la pantalla de error
 * tiraba a la basura para mostrar siempre el texto genérico. Quien cambiaba un
 * número en la URL y quien de verdad perdió un vínculo veían exactamente lo
 * mismo, y el segundo no tenía forma de saber qué había pasado.
 *
 * ── Por qué no se muestran TODOS los mensajes ──────────────────────────────
 * Sería una línea en el manejador de excepciones y estaría mal. Un 403 lo puede
 * lanzar el middleware de permisos, un Gate de Laravel o una librería de
 * terceros, y sus mensajes no están escritos para leerse: van en inglés,
 * nombran clases o describen la mecánica interna. Peor, un mensaje puede
 * confirmar la existencia de un registro ajeno a quien no debería saber que
 * existe.
 *
 * Así que el mensaje viaja sólo cuando quien lo escribió DIJO que es para el
 * usuario, usando esta clase en lugar de `abort()`. Lo demás sigue cayendo en
 * el texto genérico, que es el comportamiento seguro por omisión.
 *
 * ── Qué merece explicarse ──────────────────────────────────────────────────
 * Lo que el interesado puede entender y, si se equivocó la escuela, reclamar:
 * a quién pertenece algo, si un vínculo existe, si su cuenta está completa. NO
 * los detalles internos —«ese docente no tiene persona»— ni la existencia de
 * registros que no le tocan.
 */
final class AvisoParaElUsuario extends HttpException
{
    /**
     * Las contrapartes de `abort_if` y `abort_unless`.
     *
     * Se llaman igual que ellas a propósito: la migración de un `abort_if` es
     * cambiar el nombre, y quien lea el código ve de inmediato que la
     * diferencia está en que aquí el motivo se muestra.
     */
    public static function si(bool $condicion, int $estado, string $motivo): void
    {
        if ($condicion) {
            throw new self($estado, $motivo);
        }
    }

    public static function aMenosQue(bool $condicion, int $estado, string $motivo): void
    {
        self::si(! $condicion, $estado, $motivo);
    }

    /** Sin condición: para el `?? abort(...)` de toda la vida. */
    public static function lanzar(int $estado, string $motivo): never
    {
        throw new self($estado, $motivo);
    }

    /**
     * Qué se le puede decir al usuario sobre esta excepción. `null` = nada.
     *
     * Vive aquí y no suelto en el manejador de excepciones porque es LA regla
     * de esta clase, y en el manejador era una línea fácil de «simplificar»
     * hasta mostrar el mensaje de cualquier excepción —que es exactamente lo
     * que no debe pasar—. Aquí tiene prueba.
     */
    public static function motivoDe(\Throwable $excepcion): ?string
    {
        return $excepcion instanceof self ? $excepcion->getMessage() : null;
    }
}
