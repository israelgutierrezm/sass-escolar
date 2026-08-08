<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Landlord\ConsumoEmision;
use App\Models\Landlord\SaldoEmision;
use Illuminate\Support\Facades\DB;

/**
 * Cuántos XML ha emitido una escuela y quién los paga.
 *
 * ── Lo que hace difícil esto ───────────────────────────────────────────────
 * Un XML no siempre sale bien a la primera: un dato mal capturado, un rechazo
 * del web service de la SEP. Rehacerlo es obligatorio y NO es un consumo nuevo:
 * es el mismo trámite del mismo alumno. Cobrarlo dos veces es cobrar por un
 * error, y del lado contrario, no contar nada dejaría a la organización sin
 * saber qué se está usando.
 *
 * La salida es contar siempre y cobrar sólo la primera vez, reconociendo el
 * trámite por **CURP + plan de estudios**: identifica «el certificado de esta
 * persona para esta carrera» sin depender del folio, que cambia justamente al
 * regenerar. Y distingue el caso legítimo de dos cobros: el mismo alumno
 * titulándose de dos carreras son dos trámites.
 *
 * ── Se comprueba ANTES de firmar el lote entero ────────────────────────────
 * Firmar hasta donde alcance dejaría un lote partido —unos alumnos certificados
 * y otros no— que después hay que reconstruir a mano sabiendo por dónde se
 * quedó. Se cuenta lo que va a cobrar, se comprueba, y si no alcanza no se
 * empieza.
 */
class CreditosDeEmision
{
    /**
     * Comprueba que la escuela pueda emitir estos trámites, y si no, lo explica.
     *
     * @param  array<int, array{curp: string, plan: string}>  $tramites  Uno por
     *                                                                  documento a emitir.
     */
    public function exigirQuePueda(string $tenantId, string $tipo, array $tramites): void
    {
        $saldo = SaldoEmision::de($tenantId);

        if (! $saldo->esPrepago()) {
            return;
        }

        $aCobrar = $this->cuantosCobrarian($tenantId, $tipo, $tramites);
        $faltan = $saldo->faltanPara($aCobrar);

        AvisoParaElUsuario::si(
            $faltan > 0,
            422,
            "No hay créditos suficientes: se necesitan {$aCobrar} y quedan {$saldo->creditos}. "
                ."Faltan {$faltan}. Compra más créditos antes de firmar el lote.",
        );
    }

    /**
     * Cuántos de estos trámites cobrarían de verdad.
     *
     * Los que ya se cobraron antes —regeneraciones— no cuentan, y por eso un
     * lote de veinte puede necesitar sólo tres créditos. Los repetidos DENTRO
     * de la misma lista tampoco se cuentan dos veces: puede llegar el mismo
     * alumno dos veces por un error de armado del lote.
     *
     * @param  array<int, array{curp: string, plan: string}>  $tramites
     */
    public function cuantosCobrarian(string $tenantId, string $tipo, array $tramites): int
    {
        $vistos = [];
        $total = 0;

        foreach ($tramites as $tramite) {
            $clave = mb_strtoupper(trim($tramite['curp'])).'|'.$tramite['plan'];

            if (isset($vistos[$clave])) {
                continue;
            }

            $vistos[$clave] = true;

            if (! $this->yaSeCobro($tenantId, $tipo, $tramite['curp'], $tramite['plan'])) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Registra un XML emitido. Devuelve si cobró o no.
     *
     * Todo en una transacción: un consumo cobrado sin descontar el crédito —o
     * al revés— deja la cuenta de la organización sin cuadrar, que es
     * exactamente lo que este servicio existe para evitar.
     */
    public function registrar(
        string $tenantId,
        string $tipo,
        string $curp,
        string $planClave,
        ?string $referencia = null,
    ): bool {
        return DB::connection('central')->transaction(function () use ($tenantId, $tipo, $curp, $planClave, $referencia) {
            /*
             * El saldo se bloquea antes de mirar si cobra: dos firmas
             * simultáneas de la misma escuela leerían el mismo saldo y
             * descontarían una sola vez.
             */
            $saldo = SaldoEmision::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first() ?? SaldoEmision::de($tenantId);

            $cobra = ! $saldo->esIlimitado()
                && ! $this->yaSeCobro($tenantId, $tipo, $curp, $planClave);

            ConsumoEmision::create([
                'tenant_id' => $tenantId,
                'tipo' => $tipo,
                'curp' => mb_strtoupper(trim($curp)),
                'plan_clave' => $planClave,
                'referencia' => $referencia,
                'cobrado' => $cobra,
            ]);

            // Sólo el prepago descuenta: el postpago se cobra después y el
            // ilimitado no cobra.
            if ($cobra && $saldo->esPrepago()) {
                $saldo->decrement('creditos');
            }

            return $cobra;
        });
    }

    /**
     * Cuánto se ha consumido y cuánto está por cobrar.
     *
     * @return array{emitidos: int, cobrados: int, regenerados: int}
     */
    public function resumen(string $tenantId, ?string $desde = null): array
    {
        $consumos = ConsumoEmision::query()
            ->where('tenant_id', $tenantId)
            ->when($desde !== null, fn ($q) => $q->where('created_at', '>=', $desde))
            ->get(['cobrado']);

        return [
            'emitidos' => $consumos->count(),
            'cobrados' => $consumos->where('cobrado', true)->count(),
            // Lo que se rehízo: no cobra, pero decir cuánto es sirve para
            // detectar una escuela que reemite mucho por errores de captura.
            'regenerados' => $consumos->where('cobrado', false)->count(),
        ];
    }

    /** ¿Este trámite ya gastó un crédito alguna vez? */
    private function yaSeCobro(string $tenantId, string $tipo, string $curp, string $planClave): bool
    {
        return ConsumoEmision::query()
            ->delMismoTramite($tenantId, $tipo, $curp, $planClave)
            ->where('cobrado', true)
            ->exists();
    }
}
