<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\PasarelaPago;
use App\Services\RegistradorPago;
use App\Support\PasarelasCatalogo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El cobro en línea, de punta a punta.
 *
 * ── Las dos mitades ────────────────────────────────────────────────────────
 * `iniciar` crea la promesa y manda a quien paga con la pasarela. `conciliar`
 * recibe lo que la pasarela dice que pasó y, sólo si hay dinero, lo convierte en
 * un pago aplicado a los cargos.
 *
 * Están separadas porque ocurren en momentos distintos y por vías distintas: la
 * primera con alguien mirando la pantalla, la segunda sola, minutos u horas
 * después, quizá con el navegador ya cerrado. Un cobro en línea que dependa de
 * que el usuario vuelva a la página es un cobro que se pierde.
 *
 * ── El pago nace aquí, no antes ────────────────────────────────────────────
 * Mientras hay sólo una intención no hay dinero. Registrar el `pago` al empezar
 * llenaría la caja de cobros que nunca ocurrieron, porque la mayoría de los
 * intentos se abandonan.
 *
 * ── Idempotencia ───────────────────────────────────────────────────────────
 * Los webhooks se reintentan: es su diseño, no una falla. Y el alumno además
 * vuelve por el retorno, que también concilia. Así que el MISMO cobro llega
 * varias veces y por caminos distintos, a veces a la vez. Por eso la intención
 * se bloquea (`lockForUpdate`) y se vuelve a comprobar dentro de la transacción:
 * sin eso, dos avisos simultáneos registran el pago dos veces y el alumno queda
 * con saldo a favor que nadie depositó.
 */
class CobroEnLinea
{
    public function __construct(
        private readonly Pasarelas $pasarelas,
        private readonly RegistradorPago $registrador,
    ) {}

    /**
     * Prepara el cobro y devuelve a dónde mandar a quien paga.
     *
     * @param  array<int, int>  $adeudoIds  Los cargos que eligió pagar.
     */
    public function iniciar(
        MatriculaOferta|Aspirante $titular,
        string $clavePasarela,
        array $adeudoIds,
        string $urlRetorno,
        string $urlAviso,
        ?string $metodo = null,
    ): IntencionCobro {
        $config = PasarelaPago::para($clavePasarela);
        $pasarela = $this->pasarelas->para($config);

        /*
         * Las pasarelas sin checkout propio —OpenPay— necesitan saber la forma
         * de pago de antemano. Si no llegó, no se elige una por su cuenta: se
         * pide, porque cobrar con tarjeta a quien iba a pagar en efectivo es
         * cobrarle de una manera que no aceptó.
         */
        AvisoParaElUsuario::si(
            $pasarela->metodosAElegir() !== [] && blank($metodo),
            422,
            'Elige con qué vas a pagar antes de continuar.',
        );

        $adeudos = $this->adeudosDe($titular, $adeudoIds);

        AvisoParaElUsuario::si(
            $adeudos->isEmpty(),
            422,
            'No hay cargos por pagar entre los que elegiste. Puede que ya se hayan liquidado.',
        );

        $monto = round($adeudos->sum(fn (Adeudo $a) => $a->saldo()), 2);

        AvisoParaElUsuario::aMenosQue(
            $monto > 0,
            422,
            'Esos cargos ya no tienen saldo pendiente.',
        );

        $intencion = IntencionCobro::create([
            ...$this->columnaTitular($titular),
            'pasarela' => $clavePasarela,
            // Se sella el ambiente: si la escuela cambia a producción mientras
            // alguien paga, este cobro se sigue consultando donde nació.
            'ambiente' => $config->ambiente,
            'monto' => $monto,
            'adeudo_ids' => $adeudos->pluck('id')->all(),
        ]);

        $iniciado = $pasarela->iniciar(
            $intencion,
            $this->conIntencion($urlRetorno, $intencion->id),
            $this->conIntencion($urlAviso, $intencion->id),
            $metodo,
        );

        $intencion->update([
            'referencia_externa' => $iniciado->referenciaExterna,
            'respuesta' => $iniciado->crudo,
        ]);

        // Se devuelve con la URL puesta encima: es lo único que le falta a quien
        // llama, y guardarla en la tabla sería guardar algo que caduca.
        $intencion->url_pago = $iniciado->url;

        return $intencion;
    }

    /**
     * Convierte en dinero lo que la pasarela confirma. Es seguro llamarla mil
     * veces con el mismo resultado.
     */
    public function conciliar(ResultadoCobro $resultado): ?IntencionCobro
    {
        if ($resultado->intencionId === null) {
            Log::warning('Llegó un aviso de cobro que no se pudo atribuir a ninguna intención.', [
                'crudo' => $resultado->crudo,
            ]);

            return null;
        }

        return DB::transaction(function () use ($resultado) {
            /*
             * El bloqueo es lo que hace segura la concurrencia: el webhook y el
             * retorno del navegador pueden llegar al mismo tiempo, y sin esto
             * los dos verían la intención pendiente y registrarían el pago.
             */
            $intencion = IntencionCobro::query()
                ->whereKey($resultado->intencionId)
                ->lockForUpdate()
                ->first();

            if ($intencion === null) {
                return null;
            }

            // Ya se resolvió: el aviso es un reintento. Nada que hacer.
            if ($intencion->estaResuelta()) {
                return $intencion;
            }

            if ($resultado->estado === EstadoCobro::APROBADO) {
                $this->registrarElDinero($intencion, $resultado);

                return $intencion;
            }

            $estado = $resultado->estado->estadoDeIntencion();

            /*
             * Pendiente y desconocido dejan la intención abierta a propósito: el
             * SPEI tarda, el efectivo en tienda tarda más, y cerrar el intento
             * porque todavía no hay respuesta obligaría al alumno a pagar otra
             * vez algo que quizá ya pagó.
             */
            if ($estado !== null) {
                $intencion->update([
                    'estado' => $estado,
                    'respuesta' => $resultado->crudo,
                    'resuelta_en' => now(),
                ]);
            }

            return $intencion;
        });
    }

    /**
     * Le pregunta a la pasarela por una intención y concilia lo que responda.
     *
     * Es la red por si el aviso nunca llegó —se cayó el servidor, la pasarela no
     * pudo entregarlo, la escuela estaba sin internet—. Sin esto, un cobro
     * cuyo webhook se perdió queda pendiente para siempre aunque el dinero esté.
     */
    public function revisar(IntencionCobro $intencion): ?IntencionCobro
    {
        if ($intencion->estaResuelta()) {
            return $intencion;
        }

        $pasarela = $this->pasarelas->para(PasarelaPago::para($intencion->pasarela));

        return $this->conciliar($pasarela->consultar($intencion));
    }

    // ── Interno ────────────────────────────────────────────────────────────

    private function registrarElDinero(IntencionCobro $intencion, ResultadoCobro $resultado): void
    {
        $titular = $intencion->titular();

        if ($titular === null) {
            Log::error('Una intención de cobro aprobada se quedó sin titular.', ['intencion' => $intencion->id]);

            return;
        }

        /*
         * El monto es el que dice la PASARELA, no el que se pidió. Si por lo que
         * sea entró otra cantidad —un pago parcial, un cambio de moneda—, lo que
         * hay que registrar es el dinero que llegó; cuadrarlo contra lo que se
         * esperaba es un problema de caja, y para verlo hace falta que esté el
         * número real.
         */
        $monto = $resultado->monto ?? (float) $intencion->monto;

        $pago = $this->registrador->registrar(
            $titular,
            $this->metodoDe($intencion->pasarela),
            $monto,
            // Los cargos que eligió, filtrados a los que sigan abiertos: entre
            // que empezó a pagar y llegó el aviso pudieron condonarle uno.
            $this->adeudosDe($titular, $intencion->adeudo_ids ?? [])->pluck('id')->all() ?: null,
            referencia: 'Pago en línea',
            pasarela: $intencion->pasarela,
            pasarelaTxnId: $resultado->transaccionId,
        );

        // El método nace pidiendo confirmación —un cobro en línea no es dinero
        // hasta que la pasarela lo dice—, y esto es justo ese momento.
        $this->registrador->confirmar($pago);

        $intencion->update([
            'estado' => IntencionCobro::PAGADA,
            'pago_id' => $pago->id,
            'respuesta' => $resultado->crudo,
            'resuelta_en' => now(),
        ]);
    }

    /**
     * El método de pago con el que entra este dinero.
     *
     * Se crea la primera vez que se cobra por esa pasarela en vez de sembrarlo:
     * así una escuela que nunca usa Mercado Pago no tiene su método en la lista,
     * y la que sí lo usa lo ve aparecer con el nombre correcto. Queda editable
     * como cualquier otro —sobre todo su clave del SAT, que depende de cómo
     * facture cada escuela.
     */
    private function metodoDe(string $clavePasarela): MetodoPago
    {
        $nombre = PasarelasCatalogo::todas()[$clavePasarela]['nombre'] ?? $clavePasarela;

        return MetodoPago::firstOrCreate(
            ['clave' => 'pasarela_'.$clavePasarela],
            [
                'nombre' => $nombre.' (en línea)',
                'requiere_confirmacion' => true,
                'activo' => true,
            ],
        );
    }

    /**
     * Los cargos del titular entre los pedidos, y sólo los que siguen abiertos.
     *
     * El filtro por titular no es cortesía: sin él, cualquiera podría mandar los
     * ids de los cargos de otro alumno y pagarlos —o peor, hacer que su propio
     * pago los liquidara—.
     *
     * @param  array<int, int>  $adeudoIds
     * @return Collection<int, Adeudo>
     */
    private function adeudosDe(MatriculaOferta|Aspirante $titular, array $adeudoIds)
    {
        if ($adeudoIds === []) {
            return collect();
        }

        return Adeudo::query()
            ->when($titular instanceof Aspirante,
                fn ($q) => $q->deAspirante($titular->id),
                fn ($q) => $q->deMatricula($titular->id),
            )
            ->porCobrar()
            ->whereIn('id', $adeudoIds)
            ->orderBy('fecha_vencimiento')
            ->orderBy('id')
            ->get();
    }

    /** @return array{matricula_oferta_id: ?int, aspirante_id: ?int} */
    private function columnaTitular(MatriculaOferta|Aspirante $titular): array
    {
        return $titular instanceof Aspirante
            ? ['matricula_oferta_id' => null, 'aspirante_id' => $titular->id]
            : ['matricula_oferta_id' => $titular->id, 'aspirante_id' => null];
    }

    /** Le pega el id de la intención a una URL que ya puede traer query. */
    private function conIntencion(string $url, int $intencionId): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'intencion='.$intencionId;
    }
}
