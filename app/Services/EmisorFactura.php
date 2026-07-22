<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\TimbrarFactura;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\FacturaConcepto;
use App\Models\Finanzas\Pago;
use App\Services\Cfdi\Pac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Arma el CFDI a partir de los pagos que factura.
 *
 * Se factura contra PAGOS y no contra adeudos: el comprobante ampara dinero que
 * entró, no dinero que se espera. Facturar un adeudo pendiente emitiría un
 * comprobante fiscal por algo que el alumno todavía no pagó.
 *
 * El IVA sale del catálogo (`conceptos_pago.gravado` y `tasa_iva`), concepto
 * por concepto: en una misma factura conviven la colegiatura exenta y la
 * constancia gravada, y calcular el impuesto sobre el total mezclaría las dos.
 */
class EmisorFactura
{
    public function __construct(
        private readonly Pac $pac,
        private readonly ResolutorEmisorFiscal $resolutorEmisor,
    ) {}

    /**
     * Crea el borrador y lo manda a la cola.
     *
     * @param  array<int, int>  $pagoIds
     * @param  array<string, string>  $receptor  rfc, razon_social, uso_cfdi, regimen_fiscal, cp
     *
     * @throws RuntimeException si algún pago no se puede facturar
     */
    public function emitir(int $matriculaOfertaId, array $pagoIds, array $receptor, ?Factura $sustituyeA = null): Factura
    {
        $pagos = $this->pagosFacturables($matriculaOfertaId, $pagoIds, $sustituyeA);

        // Con qué razón social se emite. Se resuelve ANTES de crear nada: una
        // escuela con varias personas morales factura bachillerato con una y
        // posgrado con otra, y emitir a nombre equivocado no se corrige con un
        // UPDATE sino cancelando ante el SAT.
        $emisor = $this->resolutorEmisor->datosPara(
            MatriculaOferta::findOrFail($matriculaOfertaId)
        );

        return DB::transaction(function () use ($matriculaOfertaId, $pagos, $receptor, $sustituyeA, $emisor) {
            $renglones = $pagos->map(fn (Pago $pago) => $this->renglonDe($pago));

            $subtotal = round($renglones->sum('importe'), 2);
            $iva = round($renglones->sum('iva'), 2);

            $factura = Factura::create([
                'matricula_oferta_id' => $matriculaOfertaId,
                // El emisor se COPIA, igual que el receptor: si la escuela
                // corrige su razón social o cambia de régimen, el comprobante
                // ya timbrado debe seguir diciendo lo que se timbró.
                ...$emisor,
                'receptor_rfc' => strtoupper(trim($receptor['rfc'])),
                'receptor_razon_social' => trim($receptor['razon_social']),
                'receptor_uso_cfdi' => $receptor['uso_cfdi'] ?? config('cfdi.uso_cfdi_default'),
                'receptor_regimen_fiscal' => $receptor['regimen_fiscal'],
                'receptor_cp' => $receptor['cp'],
                // La forma de pago del CFDI sale del método del primer pago.
                // Cuando se mezclan métodos, el SAT admite '99 Por definir',
                // que es exactamente lo que significa "varios".
                'forma_pago_sat' => $this->formaPagoDe($pagos),
                'metodo_pago_sat' => 'PUE', // pago en una sola exhibición: se factura lo ya cobrado
                'subtotal' => $subtotal,
                'iva' => $iva,
                'total' => round($subtotal + $iva, 2),
                'pac' => $this->pac->nombre(),
                'estatus' => Factura::ESTATUS_BORRADOR,
                'factura_sustituye_id' => $sustituyeA?->id,
            ]);

            foreach ($renglones as $renglon) {
                $factura->conceptos()->create($renglon);
            }

            // El despacho va DENTRO de la transacción a propósito: la cola es
            // `database` y su tabla vive en la misma base del tenant, así que
            // si la factura no se guarda, el job tampoco existe. Con una cola
            // externa habría que usar afterCommit().
            TimbrarFactura::dispatch($factura->id);

            return $factura;
        });
    }

    /**
     * Cancela un CFDI timbrado. Motivo 01 exige la sustituta.
     *
     * NO se borra ni se edita: la cancelación es un movimiento que se registra
     * encima. Una factura cancelada libera sus pagos, que vuelven a ser
     * facturables — es justo el caso de "se emitió con el RFC equivocado".
     */
    public function cancelar(Factura $factura, string $motivo, ?Factura $sustituta = null): void
    {
        if (! $factura->estaVigente()) {
            throw new RuntimeException('Solo se cancela una factura timbrada.');
        }

        if ($motivo === Factura::MOTIVO_CON_RELACION) {
            if ($sustituta === null) {
                throw new RuntimeException('El motivo 01 exige indicar qué factura la sustituye.');
            }

            // El SAT recibe el UUID de la sustituta, así que tiene que estar ya
            // timbrada. Cancelar citando un borrador dejaría a la escuela sin
            // comprobante vigente por un dinero que sí cobró.
            if (! $sustituta->estaVigente()) {
                throw new RuntimeException('La factura que sustituye todavía no está timbrada.');
            }

            if ($sustituta->factura_sustituye_id !== $factura->id) {
                throw new RuntimeException('Esa factura no se emitió para sustituir a ésta.');
            }
        }

        $resultado = $this->pac->cancelar($factura, $motivo, $sustituta?->uuid);

        if (! $resultado->exito) {
            throw new RuntimeException($resultado->error ?? 'El PAC rechazó la cancelación.');
        }

        $factura->update([
            'estatus' => Factura::ESTATUS_CANCELADA,
            'cancelada_en' => now(),
            'motivo_cancelacion' => $motivo,
        ]);
    }

    /**
     * Refactura: emite el comprobante que SUSTITUYE a otro, con los mismos
     * pagos y los datos fiscales corregidos.
     *
     * Existe porque el orden que exige el SAT choca con la regla de "no
     * facturar dos veces el mismo dinero": para cancelar con motivo 01 hay que
     * citar el UUID de la sustituta, o sea que la sustituta tiene que existir
     * y estar timbrada ANTES de cancelar la original — y mientras tanto la
     * original sigue viva ocupando esos pagos.
     *
     * Se resuelve declarando la sustitución al emitir: una factura que ya tiene
     * sustituta viva deja de amparar sus pagos, así que la nueva puede tomarlos
     * sin que la vieja desaparezca. El orden real queda:
     *
     *   1. `refacturar()` emite la sustituta y la liga a la original.
     *   2. Cuando el PAC le da UUID, se cancela la original con motivo 01
     *      citando a la sustituta.
     *
     * Se descartó "cancelar primero y volver a facturar": deja a la escuela sin
     * ningún comprobante vigente en el hueco entre las dos operaciones, y si el
     * segundo timbrado falla, sin ninguno en absoluto.
     *
     * @param  array<string, string>  $receptor
     */
    public function refacturar(Factura $original, array $receptor): Factura
    {
        if (! $original->estaVigente()) {
            throw new RuntimeException('Solo se refactura una factura timbrada. Un borrador se corrige y se reintenta.');
        }

        if ($original->sustituida()->vivas()->exists()) {
            throw new RuntimeException('Esta factura ya tiene una sustituta en camino.');
        }

        $pagoIds = $original->conceptos()->whereNotNull('pago_id')->pluck('pago_id')->all();

        if ($pagoIds === []) {
            throw new RuntimeException('La factura original no ampara ningún pago identificable.');
        }

        return $this->emitir($original->matricula_oferta_id, $pagoIds, $receptor, $original);
    }

    /**
     * Los pagos de esta matrícula que todavía se pueden facturar.
     *
     * @return Collection<int, Pago>
     */
    public function facturables(int $matriculaOfertaId): Collection
    {
        return Pago::query()
            ->with('metodoPago')
            ->where('matricula_oferta_id', $matriculaOfertaId)
            ->cobrados()
            ->whereNotIn('id', $this->pagosYaFacturados())
            ->orderByDesc('momento')
            ->get();
    }

    /**
     * @param  array<int, int>  $pagoIds
     * @return Collection<int, Pago>
     */
    private function pagosFacturables(int $matriculaOfertaId, array $pagoIds, ?Factura $sustituyeA = null): Collection
    {
        if ($pagoIds === []) {
            throw new RuntimeException('Hay que elegir al menos un pago para facturar.');
        }

        $pagos = Pago::query()
            ->with('metodoPago')
            ->whereIn('id', $pagoIds)
            ->where('matricula_oferta_id', $matriculaOfertaId)
            ->get();

        if ($pagos->count() !== count(array_unique($pagoIds))) {
            throw new RuntimeException('Alguno de los pagos no pertenece a esta matrícula.');
        }

        // Un pago pendiente de confirmar es una promesa. Facturarlo emitiría
        // un comprobante fiscal por dinero que todavía puede no llegar.
        $sinCobrar = $pagos->reject(fn (Pago $p) => $p->estaCobrado());

        if ($sinCobrar->isNotEmpty()) {
            throw new RuntimeException(
                'Solo se factura dinero cobrado. Sin confirmar: pago #'
                .$sinCobrar->pluck('id')->implode(', #').'.'
            );
        }

        // La factura que estamos sustituyendo no cuenta como ocupante: sus
        // pagos son justamente los que la nueva viene a amparar. Sin esta
        // excepción, `refacturar` se bloquearía a sí mismo — la sustituta
        // todavía no existe cuando se comprueba la ocupación.
        $yaFacturados = $this->pagosYaFacturados($sustituyeA?->id);
        $repetidos = $pagos->filter(fn (Pago $p) => in_array($p->id, $yaFacturados, true));

        if ($repetidos->isNotEmpty()) {
            throw new RuntimeException(
                'Estos pagos ya están en una factura vigente: #'
                .$repetidos->pluck('id')->implode(', #').'. '
                .'Cancela esa factura si hay que reemitirla.'
            );
        }

        return $pagos;
    }

    /**
     * Pagos amparados por una factura viva, o sea los que no se pueden volver
     * a facturar.
     *
     * Dos excepciones, y las dos son la misma idea: una factura deja de
     * amparar sus pagos cuando ya no es el comprobante de esa operación.
     *  - Cancelada: sus pagos vuelven a estar libres. Es el punto de "cancelar
     *    y refacturar".
     *  - Con sustituta viva: la nueva ya tomó su lugar, aunque la original siga
     *    timbrada mientras se completa la cancelación con motivo 01.
     *
     * @return array<int, int>
     */
    private function pagosYaFacturados(?int $exceptoFacturaId = null): array
    {
        return FacturaConcepto::query()
            ->whereNotNull('pago_id')
            ->whereHas('factura', fn ($q) => $q
                ->vivas()
                ->when($exceptoFacturaId !== null, fn ($c) => $c->whereKeyNot($exceptoFacturaId))
                ->whereDoesntHave('sustituida', fn ($s) => $s->vivas()))
            ->pluck('pago_id')
            ->all();
    }

    /**
     * Un renglón por pago. El importe se separa en base e IVA según el
     * catálogo; si el concepto es exento, el IVA es cero y el importe es el
     * pago completo.
     *
     * @return array<string, mixed>
     */
    private function renglonDe(Pago $pago): array
    {
        $concepto = $this->conceptoDe($pago);
        $monto = (float) $pago->monto;

        $tasa = ($concepto?->gravado && $concepto->tasa_iva !== null)
            ? (float) $concepto->tasa_iva
            : 0.0;

        // El pago es lo que el alumno entregó, o sea el total CON impuesto. La
        // base se desglosa hacia atrás; hacerlo al revés (base = pago, IVA
        // encima) haría que la factura sumara más de lo que se cobró.
        $base = $tasa > 0 ? round($monto / (1 + $tasa), 2) : $monto;
        $iva = round($monto - $base, 2);

        return [
            'pago_id' => $pago->id,
            'clave_sat' => $concepto?->clave_sat ?? '86121600',
            'clave_unidad_sat' => $concepto?->clave_unidad_sat ?? 'E48',
            'descripcion' => $concepto?->nombre ?? 'Servicios educativos',
            'cantidad' => 1,
            'valor_unitario' => $base,
            'importe' => $base,
            'iva' => $iva,
        ];
    }

    /**
     * El concepto que ampara un pago: el del adeudo que cubrió. Un pago que no
     * se aplicó a nada (un anticipo) se factura como servicios educativos
     * genéricos, que es lo que ampara.
     */
    private function conceptoDe(Pago $pago): ?ConceptoPago
    {
        return $pago->adeudos()->with('concepto')->first()?->concepto;
    }

    /**
     * @param  Collection<int, Pago>  $pagos
     */
    private function formaPagoDe(Collection $pagos): string
    {
        $claves = $pagos->map(fn (Pago $p) => $p->metodoPago?->clave_sat)->filter()->unique();

        return $claves->count() === 1 ? (string) $claves->first() : '99';
    }
}
