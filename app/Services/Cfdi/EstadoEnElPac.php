<?php

declare(strict_types=1);

namespace App\Services\Cfdi;

/**
 * Lo que el PAC dice del comprobante, que no siempre es lo que dice la base.
 *
 * ── Por qué hace falta preguntarle ─────────────────────────────────────────
 * Un CFDI vive en dos sitios: aquí y en el SAT. Se separan solos, y de las dos
 * formas:
 *
 *  - Alguien cancela desde el portal del PAC o desde el del SAT, y aquí la
 *    factura sigue figurando vigente. La escuela sigue contando como ingreso un
 *    comprobante que ya no existe.
 *  - Se pidió la cancelación desde aquí y el SAT la dejó EN PROCESO esperando
 *    que el receptor la acepte. La base dice «cancelada» y el CFDI sigue vivo:
 *    la escuela cree que corrigió y no corrigió nada.
 *
 * Ninguna de las dos falla ni avisa. Se descubren en la declaración.
 *
 * ── `desconocido` no es «vigente» ──────────────────────────────────────────
 * Que el PAC no conteste es una tercera respuesta y se guarda como tal. Tratarla
 * como «todo bien» convertiría una caída del proveedor en una conciliación
 * limpia, que es la peor forma de este defecto: un informe en verde que no
 * comprobó nada.
 */
final readonly class EstadoEnElPac
{
    /** El comprobante está vivo ante el SAT. */
    public const VIGENTE = 'vigente';

    /** Ya no lo está. */
    public const CANCELADA = 'cancelada';

    /**
     * Estado de la SOLICITUD de cancelación, que es otra cosa que el estado del
     * comprobante: una factura puede estar VIGENTE con una cancelación
     * PENDIENTE de que el receptor la acepte.
     */
    public const CANCELACION_PENDIENTE = 'pendiente';

    public const CANCELACION_ACEPTADA = 'aceptada';

    public const CANCELACION_RECHAZADA = 'rechazada';

    public const CANCELACION_VENCIDA = 'vencida';

    private function __construct(
        public bool $conocido,
        public ?string $estado = null,
        public ?string $cancelacion = null,
        public ?string $error = null,
    ) {}

    public static function vigente(?string $cancelacion = null): self
    {
        return new self(conocido: true, estado: self::VIGENTE, cancelacion: $cancelacion);
    }

    public static function cancelada(?string $cancelacion = null): self
    {
        return new self(conocido: true, estado: self::CANCELADA, cancelacion: $cancelacion);
    }

    public static function desconocido(string $error): self
    {
        return new self(conocido: false, error: $error);
    }

    /**
     * Una cancelación pedida y todavía sin resolver.
     *
     * No es una discrepancia —el trámite va en camino— pero sí es información
     * que hay que enseñar: mientras el receptor no acepte, el CFDI sigue vivo y
     * la escuela no puede darlo por corregido.
     */
    public function cancelacionEnEspera(): bool
    {
        return $this->cancelacion === self::CANCELACION_PENDIENTE;
    }
}
