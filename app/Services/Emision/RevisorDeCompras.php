<?php

declare(strict_types=1);

namespace App\Services\Emision;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Landlord\CompraCreditos;
use App\Models\Landlord\SaldoEmision;
use App\Models\Landlord\SuperAdmin;
use Illuminate\Support\Facades\DB;

/**
 * Qué pasa cuando la organización revisa la compra de créditos de una escuela.
 *
 * ── Aprobar es acreditar ───────────────────────────────────────────────────
 * Hasta aquí sólo había una imagen. Al aprobar, la escuela puede emitir: es el
 * momento en que se le da algo que vale dinero, y por eso lo hace la
 * organización y no ella misma.
 *
 * ── Se bloquea ─────────────────────────────────────────────────────────────
 * Dos administradores con la cola abierta acreditarían los mismos créditos dos
 * veces por un solo pago. Mismo problema y misma solución que en los
 * comprobantes de colegiatura.
 */
class RevisorDeCompras
{
    /** Da por buena la compra y suma los créditos al saldo de la escuela. */
    public function aprobar(CompraCreditos $compra, SuperAdmin $revisor): CompraCreditos
    {
        AvisoParaElUsuario::aMenosQue(
            $revisor->puedeValidarCreditos(),
            403,
            'Tu rol no puede validar compras de créditos.',
        );

        return DB::connection('central')->transaction(function () use ($compra, $revisor) {
            $compra = CompraCreditos::query()->whereKey($compra->id)->lockForUpdate()->firstOrFail();

            AvisoParaElUsuario::si(
                $compra->estaResuelta(),
                422,
                'Esa compra ya la revisó alguien más.',
            );

            $saldo = SaldoEmision::de($compra->tenant_id);

            /*
             * Los créditos se suman aunque la escuela esté en postpago o
             * ilimitado: puede haber comprado antes de cambiar de modalidad, y
             * tirar el saldo sería quedarse con su dinero. Sólo el prepago los
             * gasta, pero el número queda ahí.
             */
            $saldo->increment('creditos', $compra->creditos);

            $compra->update([
                'estado' => CompraCreditos::APROBADA,
                'revisado_por' => $revisor->id,
                'revisado_en' => now(),
            ]);

            return $compra->fresh();
        });
    }

    /** La devuelve con un motivo. No toca el saldo. */
    public function rechazar(CompraCreditos $compra, SuperAdmin $revisor, string $motivo): CompraCreditos
    {
        AvisoParaElUsuario::aMenosQue(
            $revisor->puedeValidarCreditos(),
            403,
            'Tu rol no puede validar compras de créditos.',
        );

        return DB::connection('central')->transaction(function () use ($compra, $revisor, $motivo) {
            $compra = CompraCreditos::query()->whereKey($compra->id)->lockForUpdate()->firstOrFail();

            AvisoParaElUsuario::si(
                $compra->estaResuelta(),
                422,
                'Esa compra ya la revisó alguien más.',
            );

            $compra->update([
                'estado' => CompraCreditos::RECHAZADA,
                'motivo_rechazo' => $motivo,
                'revisado_por' => $revisor->id,
                'revisado_en' => now(),
            ]);

            return $compra->fresh();
        });
    }
}
