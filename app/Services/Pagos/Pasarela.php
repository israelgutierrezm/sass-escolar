<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use Illuminate\Http\Request;

/**
 * Lo que Acadion necesita de una pasarela de pago.
 *
 * Son tres cosas, y el orden importa porque describe el único flujo seguro:
 *
 * 1. `iniciar` — se le pide que cobre y devuelve a dónde mandar a quien paga.
 * 2. `interpretarAviso` — llega su webhook y se traduce a qué pasó de verdad.
 * 3. `consultar` — se le pregunta directamente por un cobro.
 *
 * ── La regla que no se negocia ─────────────────────────────────────────────
 * El dinero se da por bueno **preguntándole a la pasarela**, jamás por lo que
 * traiga el cuerpo del aviso ni por el navegador que vuelve de pagar. Las dos
 * cosas son texto que cualquiera puede fabricar: quien descubra la URL de
 * retorno podría liquidar su colegiatura escribiéndola a mano. Por eso
 * `interpretarAviso` recibe la petición sólo para sacar de ella QUÉ preguntar,
 * y la respuesta sale siempre de una consulta.
 */
interface Pasarela
{
    /**
     * Pide un cobro y devuelve a dónde enviar a quien paga.
     *
     * @param  string  $urlRetorno  a dónde vuelve el navegador (informativo).
     * @param  string  $urlAviso  a dónde manda la pasarela su webhook (vinculante).
     * @param  string|null  $metodo  con qué se va a pagar, cuando la pasarela
     *                               exige saberlo de antemano (ver
     *                               `metodosAElegir`). `null` en las que
     *                               presentan un checkout con todas.
     */
    public function iniciar(
        IntencionCobro $intencion,
        string $urlRetorno,
        string $urlAviso,
        ?string $metodo = null,
    ): CobroIniciado;

    /**
     * Las formas de pago entre las que hay que elegir ANTES de salir.
     *
     * ── Por qué existe ─────────────────────────────────────────────────────
     * Casi todas las pasarelas dan una liga a un checkout propio donde quien
     * paga elige allí mismo entre tarjeta, efectivo o transferencia. OpenPay no:
     * cobra por cargo y hay que decirle desde el principio cuál es, así que la
     * elección tiene que ocurrir en nuestra pantalla.
     *
     * Devolver una lista vacía —lo normal— significa «no preguntes nada, manda
     * a la liga y ya». Fingir que todas funcionan igual habría dejado a OpenPay
     * cobrando sólo con tarjeta y llamándolo por su nombre completo.
     *
     * @return array<int, array{clave: string, etiqueta: string}>
     */
    public function metodosAElegir(): array;

    /**
     * Qué dice la pasarela de este aviso.
     *
     * Devuelve `null` si la petición no es un aviso reconocible —hay pasarelas
     * que mandan avisos de otras cosas—, para poder contestarles «recibido» sin
     * tocar nada.
     */
    public function interpretarAviso(Request $peticion): ?ResultadoCobro;

    /** Qué dice la pasarela de este cobro, preguntándoselo directamente. */
    public function consultar(IntencionCobro $intencion): ResultadoCobro;

    /**
     * ¿El aviso viene de verdad de la pasarela?
     *
     * Se comprueba ANTES de interpretarlo. Aun así la verdad sale de la
     * consulta: la firma dice quién habla, no cuánto dinero entró.
     */
    public function avisoAutentico(Request $peticion, PasarelaPago $config): bool;
}
