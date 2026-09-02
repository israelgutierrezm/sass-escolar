<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConvenioPago;
use App\Models\Identidad\Usuario;
use App\Services\ConvenioDePago;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Convenios de pago: reprogramar una deuda sin perdonarla.
 *
 * ── Permiso propio, y no el de cobrar ──────────────────────────────────────
 * Firmar un convenio decide CUÁNDO se le cobra a una persona, y con ello si
 * queda bloqueada o no. Es una autorización, no una operación de mostrador:
 * quien cobra todo el día no tiene por qué poder darle seis meses a nadie.
 * Mismo criterio que autorizar una beca o un corte de caja.
 */
class ConvenioPagoController extends Controller
{
    use AcotaPorCampus;

    public function __construct(private readonly ConvenioDePago $convenios) {}

    /** La supervisión: todos los convenios, con lo que les falta. */
    public function index(Request $peticion): Response
    {
        $consulta = ConvenioPago::query()
            ->with([
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'matricula.oferta.programaAcademico:id,nombre',
                'concepto:id,nombre',
                'autorizador.persona:id,nombre,primer_apellido,segundo_apellido',
            ]);

        // Los convenios son de un alumno, y el alumno es de un campus.
        $this->acotarMatriculas($consulta, $peticion, 'matricula');

        $estatus = (string) $peticion->query('estatus', '');

        return Inertia::render('Finanzas/Convenios/Index', [
            'convenios' => $consulta
                ->when($estatus !== '', fn ($q) => $q->where('estatus', $estatus))
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn (ConvenioPago $c) => $this->resumen($c)),
            'filtros' => ['estatus' => $estatus],
            'estatuses' => [
                ['valor' => ConvenioPago::VIGENTE, 'texto' => 'Vigentes'],
                ['valor' => ConvenioPago::CUMPLIDO, 'texto' => 'Cumplidos'],
                ['valor' => ConvenioPago::INCUMPLIDO, 'texto' => 'Incumplidos'],
                ['valor' => ConvenioPago::CANCELADO, 'texto' => 'Cancelados'],
            ],
        ]);
    }

    /** Los cargos que se pueden acordar de este alumno. */
    public function elegibles(Request $peticion, MatriculaOferta $matricula): JsonResponse
    {
        $matricula->loadMissing('oferta:id,campus_id');
        $this->autorizarMatricula($peticion, $matricula);

        return response()->json([
            'cargos' => $this->convenios->elegibles($matricula)->map(fn (Adeudo $a) => [
                'id' => $a->id,
                'concepto_id' => $a->concepto_id,
                'concepto' => $a->concepto?->nombre,
                'periodo' => $a->periodo_etiqueta,
                'vencimiento' => $a->fecha_vencimiento?->toDateString(),
                'saldo' => $a->saldo(),
                'vencido' => $a->estaVencido(),
            ])->values(),
            'vigente' => ($v = $this->convenios->vigenteDe($matricula)) === null ? null : $this->resumen($v),
        ]);
    }

    public function crear(Request $peticion, MatriculaOferta $matricula): RedirectResponse
    {
        $matricula->loadMissing('oferta:id,campus_id');
        $this->autorizarMatricula($peticion, $matricula);

        $datos = $peticion->validate([
            'adeudo_ids' => ['required', 'array', 'min:1'],
            'adeudo_ids.*' => ['required', 'integer'],
            'parcialidades' => ['required', 'array', 'min:1'],
            'parcialidades.*.fecha' => ['required', 'date'],
            'parcialidades.*.monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'max:255'],
            'firmado_en' => ['required', 'date'],
        ], [
            'motivo.required' => 'Escribe por qué se acuerda: sin la razón, dentro de un año nadie podrá explicar por qué a este alumno se le dieron esos plazos.',
        ]);

        $usuario = $peticion->user();

        $convenio = $this->convenios->crear(
            $matricula,
            array_map('intval', $datos['adeudo_ids']),
            array_map(
                fn (array $p) => ['fecha' => $p['fecha'], 'monto' => (float) $p['monto']],
                $datos['parcialidades'],
            ),
            $datos['motivo'],
            $datos['firmado_en'],
            $usuario instanceof Usuario ? $usuario : null,
        );

        return back(303)->with(
            'exito',
            "Convenio #{$convenio->id} firmado: ".$convenio->parcialidades()->count()
            .' parcialidad(es). Los cargos acordados dejan de generar mora.'
        );
    }

    public function cancelar(Request $peticion, ConvenioPago $convenio): RedirectResponse
    {
        $this->autorizarConvenio($peticion, $convenio);

        $datos = $peticion->validate(['motivo' => ['required', 'string', 'max:255']]);

        $this->convenios->cancelar($convenio, $datos['motivo']);

        return back(303)->with('exito', 'Convenio cancelado. Los cargos originales vuelven a estar por cobrar.');
    }

    public function incumplir(Request $peticion, ConvenioPago $convenio): RedirectResponse
    {
        $this->autorizarConvenio($peticion, $convenio);

        $datos = $peticion->validate(['motivo' => ['required', 'string', 'max:255']]);

        $n = $this->convenios->incumplir($convenio, $datos['motivo']);

        return back(303)->with(
            'exito',
            "Convenio declarado incumplido: {$n} parcialidad(es) quedaron vencidas de inmediato."
        );
    }

    private function autorizarConvenio(Request $peticion, ConvenioPago $convenio): void
    {
        $matricula = $convenio->matricula;

        if ($matricula !== null) {
            $matricula->loadMissing('oferta:id,campus_id');
            // El id viaja en la URL: filtrar el listado no es la defensa.
            $this->autorizarMatricula($peticion, $matricula);
        }
    }

    /** @return array<string, mixed> */
    private function resumen(ConvenioPago $convenio): array
    {
        return [
            'id' => $convenio->id,
            'alumno' => $convenio->matricula?->persona?->nombreCompleto(),
            'matricula' => $convenio->matricula?->matricula,
            'matricula_id' => $convenio->matricula_oferta_id,
            'programa_academico' => $convenio->matricula?->oferta?->programaAcademico?->nombre,
            'concepto' => $convenio->concepto?->nombre,
            'motivo' => $convenio->motivo,
            'firmado_en' => $convenio->firmado_en?->toDateString(),
            'monto_cubierto' => (float) $convenio->monto_cubierto,
            'saldo' => $convenio->saldo(),
            'estatus' => $convenio->estatus,
            'con_atraso' => $convenio->estaVigente() && $convenio->tieneAtraso(),
            'autorizo' => $convenio->autorizador?->persona?->nombreCompleto(),
            'cerrado_en' => $convenio->cerrado_en?->format('d/m/Y H:i'),
            'motivo_cierre' => $convenio->motivo_cierre,
            'parcialidades' => $convenio->parcialidades->map(fn (Adeudo $a) => [
                'id' => $a->id,
                'vencimiento' => $a->fecha_vencimiento?->toDateString(),
                'monto' => (float) $a->monto_total,
                'saldo' => $a->saldo(),
                'estatus' => $a->estatus,
                'vencido' => $a->estaVencido(),
            ])->values(),
        ];
    }
}
