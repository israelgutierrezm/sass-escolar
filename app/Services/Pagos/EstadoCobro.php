<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Models\Finanzas\IntencionCobro;

/**
 * En qué acabó un cobro, dicho igual para todas las pasarelas.
 *
 * Cada una tiene su vocabulario —`approved`, `succeeded`, `COMPLETED`,
 * `completed`— y traducirlo en cada sitio que lo consulte sería repartir por
 * toda la aplicación el conocimiento de una API externa. Aquí entra la jerga de
 * cada pasarela y sale una sola palabra.
 *
 * ── DESCONOCIDO no es un error ─────────────────────────────────────────────
 * Es la respuesta honesta cuando la pasarela no contesta o dice algo que no
 * sabemos leer, y es importante que exista: sin él habría que elegir entre dar
 * por bueno un cobro que no se pudo verificar o darlo por fallido cuando quizá
 * sí entró. Con él, la intención se queda pendiente y alguien puede revisarla.
 */
enum EstadoCobro: string
{
    /** Hay dinero. Es el único que registra un pago. */
    case APROBADO = 'aprobado';

    /** En proceso: SPEI, efectivo en tienda, revisión antifraude. */
    case PENDIENTE = 'pendiente';

    /** La pasarela lo rechazó (tarjeta declinada, fondos, antifraude). */
    case RECHAZADO = 'rechazado';

    /** Quien pagaba se arrepintió antes de terminar. */
    case CANCELADO = 'cancelado';

    /** No se pudo saber. Ver la nota de arriba. */
    case DESCONOCIDO = 'desconocido';

    /** Cómo se guarda esto en la intención. `null` = déjala pendiente. */
    public function estadoDeIntencion(): ?string
    {
        return match ($this) {
            self::APROBADO => IntencionCobro::PAGADA,
            self::RECHAZADO => IntencionCobro::FALLIDA,
            self::CANCELADO => IntencionCobro::CANCELADA,
            self::PENDIENTE, self::DESCONOCIDO => null,
        };
    }

    /**
     * Lo que se le dice a quien acaba de pagar.
     *
     * Sin «tu pago» ni «tus cargos»: a esta pantalla llega también un padre que
     * acaba de pagar la cuenta de su hijo, y decirle que se aplicó «a tus
     * cargos» es hablarle de una deuda que no es suya.
     */
    public function mensaje(): string
    {
        return match ($this) {
            self::APROBADO => 'El pago se recibió y ya está aplicado a los cargos.',
            self::PENDIENTE => 'El pago está en proceso. En cuanto el banco lo confirme se aplicará a los cargos.',
            self::RECHAZADO => 'El pago fue rechazado. No se hizo ningún cargo; puedes intentar con otro medio.',
            self::CANCELADO => 'Se canceló el pago. No se hizo ningún cargo.',
            self::DESCONOCIDO => 'Todavía estamos confirmando el pago. Si el cargo ya se hizo, aparecerá en el estado de cuenta en unos minutos.',
        };
    }
}
