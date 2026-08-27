<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Servicio;
use App\Models\Finanzas\SolicitudServicio;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaDeModulo;
use App\Panel\TarjetaPanel;

/**
 * La solicitud de servicios, en el panel del alumno.
 *
 * Es la única puerta: la sección no cuelga del menú lateral.
 *
 * Muestra lo que tiene ABIERTO, no cuántos servicios hay en el catálogo. Un
 * número de catálogo no cambia nunca y se vuelve mobiliario; lo que cambia —y lo
 * que la persona quiere saber al abrir el panel— es si algo suyo está esperando
 * algo. Por eso el pie dice cuántas esperan pago: es lo único de esta tarjeta
 * sobre lo que se puede actuar hoy.
 */
class MisSolicitudes implements TarjetaDeModulo, TarjetaPanel
{
    /**
     * Se DECLARA en vez de inyectarse.
     *
     * Estas eran las dos únicas tarjetas que comprobaban su módulo por su
     * cuenta, y así funcionaban — pero con la comprobación repartida, la que se
     * olvide no falla: se pinta. Es lo que le pasó a «Postulantes en proceso».
     * Ahora lo mira `RegistroTarjetas::para()`, en un solo sitio.
     */
    public function modulo(): string
    {
        return 'servicios';
    }

    public function clave(): string
    {
        return 'mis-solicitudes';
    }

    public function titulo(): string
    {
        return 'Servicios y trámites';
    }

    public function permiso(): ?string
    {
        return 'solicitar-servicios';
    }

    public function tipo(): string
    {
        return 'metrica';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        // El módulo lo comprueba el registro; aquí queda lo que es de la
        // PERSONA: sin expediente no hay solicitudes suyas que contar.
        if ($usuario->persona_id === null) {
            return null;
        }

        // Sin nada que pedir, la tarjeta sólo llevaría a un catálogo vacío.
        if (! Servicio::query()->ofrecidos()->exists()) {
            return null;
        }

        $abiertas = SolicitudServicio::query()
            ->abiertas()
            ->whereIn('matricula_oferta_id', MatriculaOferta::query()
                ->where('persona_id', $usuario->persona_id)
                ->select('id'))
            ->with('adeudo')
            ->get();

        $esperandoPago = $abiertas->filter->esperandoPago()->count();

        return [
            'valor' => $abiertas->count(),
            'formato' => 'entero',
            'pie' => $this->pie($abiertas->count(), $esperandoPago),
            // La alerta tiñe la tarjeta entera: un trámite detenido por un pago
            // que nadie hizo es justo lo que hay que ver desde lejos.
            'alerta' => $esperandoPago > 0,
            'enlace' => '/servicios',
        ];
    }

    private function pie(int $abiertas, int $esperandoPago): string
    {
        if ($esperandoPago > 0) {
            return $esperandoPago === 1
                ? 'una espera tu pago'
                : "{$esperandoPago} esperan tu pago";
        }

        return match ($abiertas) {
            0 => 'sin trámites en curso',
            1 => 'trámite en curso',
            default => 'trámites en curso',
        };
    }
}
