<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\BitacoraAcceso;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bitácora de accesos para la escuela: quién entró y salió, desde qué equipo,
 * navegador e IP, con una gráfica de los últimos días y el registro a detalle.
 */
class AccesosController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'tipo' => $request->query('tipo'),
        ];

        $registro = BitacoraAcceso::query()
            ->with('persona:id,nombre,primer_apellido,segundo_apellido')
            ->when($filtros['busqueda'] !== '', fn ($q) => $q->whereHas('persona', fn ($p) => $p
                ->whereRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) like ?", ["%{$filtros['busqueda']}%"])))
            ->when($filtros['tipo'], fn ($q, $v) => $q->where('tipo', $v))
            ->orderByDesc('creado_en')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (BitacoraAcceso $b) => [
                'id' => $b->id,
                'persona' => $b->persona?->nombreCompleto() ?? '—',
                'tipo' => $b->tipo,
                'ip' => $b->ip,
                'navegador' => $b->navegador,
                'equipo' => $b->equipo,
                'momento' => $b->creado_en?->toDateTimeString(),
            ]);

        return Inertia::render('Accesos/Index', [
            'registro' => $registro,
            'filtros' => $filtros,
            'porDia' => $this->porDia(),
            'resumen' => $this->resumen(),
        ]);
    }

    /**
     * Entradas por día de los últimos 14 días, con los días vacíos en cero para
     * que la gráfica no tenga huecos.
     *
     * @return array<int, array{dia: string, total: int}>
     */
    private function porDia(): array
    {
        $desde = Carbon::now()->subDays(13)->startOfDay();

        $conteo = BitacoraAcceso::query()
            ->where('tipo', BitacoraAcceso::ENTRADA)
            ->where('creado_en', '>=', $desde)
            ->selectRaw('DATE(creado_en) as dia, count(*) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $salida = [];

        for ($i = 0; $i < 14; $i++) {
            $dia = $desde->copy()->addDays($i)->toDateString();
            $salida[] = ['dia' => $dia, 'total' => (int) ($conteo[$dia] ?? 0)];
        }

        return $salida;
    }

    /**
     * @return array<string, int>
     */
    private function resumen(): array
    {
        $hoy = Carbon::now()->startOfDay();
        $semana = Carbon::now()->subDays(6)->startOfDay();

        return [
            'entradas_hoy' => BitacoraAcceso::query()
                ->where('tipo', BitacoraAcceso::ENTRADA)->where('creado_en', '>=', $hoy)->count(),
            'entradas_semana' => BitacoraAcceso::query()
                ->where('tipo', BitacoraAcceso::ENTRADA)->where('creado_en', '>=', $semana)->count(),
            'cuentas_semana' => BitacoraAcceso::query()
                ->where('tipo', BitacoraAcceso::ENTRADA)->where('creado_en', '>=', $semana)
                ->distinct('persona_id')->count('persona_id'),
        ];
    }
}
