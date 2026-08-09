<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Landlord\CompraCreditos;
use App\Models\Landlord\SaldoEmision;
use App\Models\Tenant;
use App\Services\Emision\CreditosDeEmision;
use App\Services\Emision\RevisorDeCompras;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Los créditos de emisión, desde el lado de la organización.
 *
 * Aquí se ve lo que cada escuela consume, se validan sus compras y se decide
 * con qué modalidad se le cobra. Todo esto es de la casa, no de la escuela: por
 * eso vive en el panel central y lo hacen `super_admins`.
 */
class CreditosController extends Controller
{
    public function __construct(
        private readonly RevisorDeCompras $revisor,
        private readonly CreditosDeEmision $creditos,
    ) {}

    public function index(Request $request): View
    {
        $estado = $request->query('estado', CompraCreditos::PENDIENTE);

        $compras = CompraCreditos::query()
            ->with('revisor:id,nombre')
            ->when(
                in_array($estado, [CompraCreditos::PENDIENTE, CompraCreditos::APROBADA, CompraCreditos::RECHAZADA], true),
                fn ($q) => $q->where('estado', $estado),
            )
            // Lo más viejo primero: quien lleva más esperando puede estar sin
            // poder emitir.
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (CompraCreditos $c) => [
                'id' => $c->id,
                'escuela' => $c->tenant_id,
                'creditos' => $c->creditos,
                'monto' => $c->monto === null ? null : (float) $c->monto,
                'referencia' => $c->referencia,
                'estado' => $c->estado,
                'motivo_rechazo' => $c->motivo_rechazo,
                'revisor' => $c->revisor?->nombre,
                'revisado_en' => $c->revisado_en?->toDateTimeString(),
                'cuando' => $c->created_at?->toDateTimeString(),
                'tiene_comprobante' => filled($c->comprobante),
            ]);

        return view('central.creditos.index', [
            'compras' => $compras,
            'estado' => $estado,
            'pendientes' => CompraCreditos::pendientes()->count(),
            // El estado de cada escuela: modalidad, saldo y consumo.
            'escuelas' => SaldoEmision::query()
                ->orderBy('tenant_id')
                ->get()
                ->map(fn (SaldoEmision $s) => [
                    'tenant_id' => $s->tenant_id,
                    'modalidad' => $s->modalidad,
                    'creditos' => $s->creditos,
                    'consumo' => $this->creditos->resumen($s->tenant_id),
                ]),
            'modalidades' => [
                ['valor' => SaldoEmision::PREPAGO, 'texto' => 'Prepago (compra créditos)'],
                ['valor' => SaldoEmision::POSTPAGO, 'texto' => 'Postpago (se cobra al final)'],
                ['valor' => SaldoEmision::ILIMITADO, 'texto' => 'Ilimitado (incluido)'],
            ],
            'puedeValidar' => $request->user('central')?->puedeValidarCreditos() ?? false,
        ]);
    }

    public function aprobar(Request $request, CompraCreditos $compra): RedirectResponse
    {
        $this->revisor->aprobar($compra, $request->user('central'));

        return back()->with('exito', "Compra aprobada: se acreditaron {$compra->creditos} créditos.");
    }

    public function rechazar(Request $request, CompraCreditos $compra): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Escribe por qué se rechaza: la escuela necesita saber qué corregir.',
        ]);

        $this->revisor->rechazar($compra, $request->user('central'), $datos['motivo']);

        return back()->with('advertencia', 'Compra rechazada. La escuela verá el motivo.');
    }

    /**
     * Cambia con qué modalidad se le cobra a una escuela.
     *
     * Es la decisión comercial —«a esta le incluimos la emisión»— y por eso vive
     * aquí y no en el panel de la escuela: si pudiera cambiarla ella, se pondría
     * en ilimitado.
     */
    public function modalidad(Request $request, string $tenantId): RedirectResponse
    {
        AvisoParaElUsuario::aMenosQue(
            $request->user('central')?->puedeValidarCreditos() ?? false,
            403,
            'Tu rol no puede cambiar la modalidad de cobro.',
        );

        $datos = $request->validate([
            'modalidad' => ['required', 'in:prepago,postpago,ilimitado'],
            'creditos' => ['nullable', 'integer'],
        ]);

        $saldo = SaldoEmision::de($tenantId);
        $saldo->modalidad = $datos['modalidad'];

        // El saldo sólo se toca si se dice explícitamente: cambiar de modalidad
        // no debe borrar créditos que la escuela pagó.
        if (array_key_exists('creditos', $datos) && $datos['creditos'] !== null) {
            $saldo->creditos = $datos['creditos'];
        }

        $saldo->save();

        return back()->with('exito', "La escuela {$tenantId} quedó en {$datos['modalidad']}.");
    }

    /**
     * El comprobante que subió la escuela.
     *
     * El archivo vive en el disco de ESA escuela, así que hay que entrar a su
     * contexto para leerlo. Es el precio de no duplicar archivos ni dejarlos en
     * un disco compartido: un comprobante bancario es de quien lo subió.
     */
    public function comprobante(CompraCreditos $compra)
    {
        AvisoParaElUsuario::aMenosQue(filled($compra->comprobante), 404, 'Esa compra no trae comprobante.');

        $tenant = Tenant::find($compra->tenant_id);

        AvisoParaElUsuario::aMenosQue($tenant !== null, 404, 'Esa escuela ya no existe.');

        $contenido = $tenant->run(fn () => Storage::disk('local')->exists($compra->comprobante)
            ? Storage::disk('local')->get($compra->comprobante)
            : null);

        AvisoParaElUsuario::aMenosQue($contenido !== null, 404, 'El archivo del comprobante ya no está.');

        return response($contenido)->header('Content-Type', $this->tipoDe($compra->comprobante));
    }

    private function tipoDe(string $ruta): string
    {
        return str_ends_with(mb_strtolower($ruta), '.pdf') ? 'application/pdf' : 'image/jpeg';
    }
}
