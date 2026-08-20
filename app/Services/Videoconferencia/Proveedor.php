<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

use App\Models\Lms\CuentaVideo;
use App\Models\Lms\Videoconferencia;
use Carbon\CarbonInterface;

/**
 * Lo que Acadion necesita de un proveedor de clase en línea.
 *
 * Tres cosas, y ninguna más: crear la sala, cancelarla y —cuando el proveedor lo
 * permita— decir dónde quedó la grabación. Todo lo demás (a quién le toca qué
 * licencia, quién puede entrar y cuándo) es de Acadion y no se le delega: son
 * las reglas de la escuela, no las de Zoom.
 *
 * ── Crear recibe la CUENTA, no la busca ────────────────────────────────────
 * Cuál cuenta usar es una decisión de disponibilidad que toma
 * `AsignadorDeCuenta` mirando las clases ya programadas. Si cada proveedor la
 * eligiera por su cuenta, la regla del traslape viviría repetida en cada uno y
 * el día que entrara un tercero volvería a escribirse mal.
 */
interface Proveedor
{
    /**
     * Crea la sala y devuelve sus enlaces.
     *
     * Puede lanzar `AvisoParaElUsuario`: lo que falla aquí es un servicio
     * externo, y quien programa la clase necesita leer por qué en vez de un 500.
     */
    public function crear(
        CuentaVideo $cuenta,
        string $titulo,
        CarbonInterface $inicio,
        int $minutos,
    ): SesionCreada;

    /**
     * Cancela la sala en el proveedor.
     *
     * Se hace además de marcarla cancelada aquí: una reunión que sigue viva del
     * otro lado deja entrar a quien guardó el enlace, y el alumno que llega ahí
     * a la hora de otra clase no entiende qué está viendo.
     */
    public function cancelar(Videoconferencia $sesion): void;
}
