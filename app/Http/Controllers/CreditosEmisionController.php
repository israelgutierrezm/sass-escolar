<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Landlord\CompraCreditos;
use App\Models\Landlord\ConsumoEmision;
use App\Models\Landlord\SaldoEmision;
use App\Services\Emision\CreditosDeEmision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que la escuela ve de sus créditos de emisión: cuánto le queda, qué ha
 * usado y cómo comprar más.
 *
 * ── Aquí sólo se mira y se pide ────────────────────────────────────────────
 * La escuela no puede cambiar su modalidad ni acreditarse créditos: eso lo
 * decide la organización que la administra. Lo único que hace desde aquí es
 * subir el comprobante de una compra, que alguien de fuera valida.
 */
class CreditosEmisionController extends Controller
{
    public function __construct(private readonly CreditosDeEmision $creditos) {}

    public function index(Request $request): Response
    {
        $tenantId = tenant()->getTenantKey();
        $saldo = SaldoEmision::de($tenantId);

        return Inertia::render('Plataforma/CreditosEmision', [
            'saldo' => $saldo->paraPantalla(),
            'resumen' => $this->creditos->resumen($tenantId),
            'ultimos' => ConsumoEmision::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (ConsumoEmision $c) => [
                    'tipo' => $c->tipo,
                    'curp' => $c->curp,
                    'plan' => $c->plan_clave,
                    'referencia' => $c->referencia,
                    'cobrado' => $c->cobrado,
                    'cuando' => $c->created_at?->toDateTimeString(),
                ]),
            'compras' => CompraCreditos::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (CompraCreditos $c) => [
                    'id' => $c->id,
                    'creditos' => $c->creditos,
                    'monto' => $c->monto === null ? null : (float) $c->monto,
                    'referencia' => $c->referencia,
                    'estado' => $c->estado,
                    'motivo_rechazo' => $c->motivo_rechazo,
                    'cuando' => $c->created_at?->toDateTimeString(),
                    'revisado_en' => $c->revisado_en?->toDateTimeString(),
                ]),
        ]);
    }

    /** Sube el comprobante de una compra de créditos. */
    public function comprar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'creditos' => ['required', 'integer', 'min:1', 'max:100000'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'creditos.required' => 'Di cuántos créditos compraste.',
            'comprobante.required' => 'Adjunta el comprobante del pago.',
        ]);

        /*
         * El archivo se guarda en el disco de ESTA escuela, que es donde se
         * subió. El panel central lo lee entrando al tenant —ver
         * `Central\CreditosController::comprobante`—, así que no hace falta
         * copiarlo a un disco compartido ni dejarlo público.
         */
        $ruta = $request->file('comprobante')->store('compras-creditos', 'local');

        CompraCreditos::create([
            'tenant_id' => tenant()->getTenantKey(),
            'creditos' => $datos['creditos'],
            'monto' => $datos['monto'] ?? null,
            'referencia' => $datos['referencia'] ?? null,
            'comprobante' => $ruta,
        ]);

        return back()->with(
            'exito',
            'Recibimos tu comprobante. En cuanto se valide, los créditos aparecerán en tu saldo.',
        );
    }

    /** El comprobante que subió la escuela, para que pueda volver a verlo. */
    public function comprobante(CompraCreditos $compra)
    {
        AvisoParaElUsuario::aMenosQue(
            $compra->tenant_id === tenant()->getTenantKey(),
            404,
            'Esa compra no es de esta escuela.',
        );

        AvisoParaElUsuario::aMenosQue(
            filled($compra->comprobante) && Storage::disk('local')->exists($compra->comprobante),
            404,
            'El archivo del comprobante ya no está.',
        );

        return Storage::disk('local')->response($compra->comprobante);
    }
}
