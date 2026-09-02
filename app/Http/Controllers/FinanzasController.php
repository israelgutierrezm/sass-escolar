<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Documentos\ReciboDeCaja;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Concerns\VeLaCarteraDelAlumno;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SituacionPago;
use App\Services\CalculadorRecargos;
use App\Services\ConvenioDePago;
use App\Services\EstadoCuenta;
use App\Services\Finanzas\SaldosDeCartera;
use App\Services\GeneradorAdeudos;
use App\Services\Pagos\Pasarelas;
use App\Services\RegistradorPago;
use App\Services\ResolutorPlanCobro;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * La cartera de la escuela y el estado de cuenta de cada alumno.
 *
 * El saldo NO se calcula alumno por alumno en PHP: el listado lo agrega en una
 * sola consulta con subconsulta de lo aplicado. Con mil matrículas, recorrerlas
 * pidiéndole el saldo a cada modelo son miles de consultas, y esta es
 * justamente la pantalla que se abre a diario.
 */
class FinanzasController extends Controller
{
    use AcotaPorCampus;

    /*
     * Quién ve la cuenta de quién vive en el trait: al aparecer el cobro en
     * línea la misma regla hacía falta en otro controlador, y esta decisión ya
     * se descompuso una vez por estar escrita dos veces.
     */
    use VeLaCarteraDelAlumno;

    public function __construct(
        private readonly EstadoCuenta $estadoCuenta,
        private readonly GeneradorAdeudos $generador,
        private readonly RegistradorPago $registrador,
        private readonly CalculadorRecargos $recargos,
        private readonly ResolutorPlanCobro $resolutor,
    ) {}

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q', ''));
        $soloDeudores = $request->boolean('deudores');
        $soloVencidos = $request->boolean('vencidos');
        $hoy = now()->toDateString();

        // ¿Ve la cartera de la escuela, o sólo lo que le toca? La misma
        // permission `ver-adeudos` la tienen el administrativo de finanzas, el
        // alumno y el padre de familia; lo que cambia es SOBRE QUIÉN.
        $alcance = $this->alcance($request);
        $visibles = $this->matriculasVisibles($request);
        $acotado = $visibles !== null;

        $consulta = MatriculaOferta::query()
            ->leftJoinSub($this->saldosPorMatricula($hoy), 'f', 'f.matricula_oferta_id', '=', 'matricula_oferta.id')
            ->join('personas', 'personas.id', '=', 'matricula_oferta.persona_id')
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.programaAcademico:id,nombre', 'oferta.campus:id,nombre'])
            ->when($acotado, fn ($q) => $q->whereIn('matricula_oferta.id', $visibles))
            ->select([
                'matricula_oferta.*',
                DB::raw('coalesce(f.saldo, 0) as saldo'),
                DB::raw('coalesce(f.vencido, 0) as vencido'),
                DB::raw('coalesce(f.adeudos, 0) as adeudos_abiertos'),
            ]);

        // Además de la faceta, el ALCANCE POR CAMPUS: un coordinador acotado a
        // un campus no debe ver la cartera de los otros. Son dos recortes
        // distintos y se aplican los dos.
        $this->acotarMatriculas($consulta, $request);

        // El buscador y los filtros de cartera solo tienen sentido sobre un
        // universo de muchos. Sobre las propias matrículas —o las de los hijos,
        // que son dos o tres— no se busca a nadie.
        if (! $acotado && $busqueda !== '') {
            $consulta->where(function ($q) use ($busqueda) {
                $q->where('matricula_oferta.matricula', 'like', "%{$busqueda}%")
                    ->orWhere('personas.curp', 'like', "%{$busqueda}%")
                    ->orWhereRaw(
                        "concat_ws(' ', personas.nombre, personas.primer_apellido, personas.segundo_apellido) like ?",
                        ["%{$busqueda}%"]
                    );
            });
        }

        if ($soloDeudores) {
            $consulta->having('saldo', '>', 0);
        }

        if ($soloVencidos) {
            $consulta->having('vencido', '>', 0);
        }

        $matriculas = $consulta
            ->orderByDesc('vencido')
            ->orderByDesc('saldo')
            ->orderBy('matricula_oferta.matricula')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre' => $m->persona?->nombreCompleto() ?? '',
                'programa_academico' => $m->oferta?->programaAcademico?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
                'estatus' => $m->estatus,
                'saldo' => round((float) $m->saldo, 2),
                'vencido' => round((float) $m->vencido, 2),
                'adeudos' => (int) $m->adeudos_abiertos,
            ]);

        // Los totales se sacan de la misma agregación, sin el paginado: si
        // salieran de la página actual dirían "la cartera son 40 mil pesos"
        // cuando son los 40 mil de los 25 alumnos que se están viendo. Cuando
        // es un alumno, se acotan a lo suyo por la misma razón que la lista:
        // no debe ver el total de la escuela.
        $totales = DB::query()
            ->fromSub($this->saldosPorMatricula($hoy, $visibles), 'f')
            ->selectRaw('coalesce(sum(f.saldo), 0) as saldo, coalesce(sum(f.vencido), 0) as vencido, count(*) as deudores')
            ->first();

        return Inertia::render('Finanzas/Index', [
            'matriculas' => $matriculas,
            'filtros' => ['q' => $busqueda, 'deudores' => $soloDeudores, 'vencidos' => $soloVencidos],
            // La vista oculta el buscador, los filtros y el encabezado de
            // "cartera de la escuela" cuando no se está viendo toda.
            'alcance' => $alcance,
            'totales' => [
                'saldo' => round((float) ($totales->saldo ?? 0), 2),
                'vencido' => round((float) ($totales->vencido ?? 0), 2),
                'deudores' => (int) ($totales->deudores ?? 0),
            ],
            'puedeRegistrarPagos' => $request->user()->can('registrar-pagos'),
        ]);
    }

    public function cuenta(Request $request, MatriculaOferta $matricula): Response
    {
        // Mismo criterio que el listado y que el cobro en línea: la regla vive
        // en el trait porque tenerla escrita dos veces fue justo lo que se
        // descompuso.
        $this->exigirQuePuedaVerLaCuenta($request, $matricula);

        $matricula->load(['persona', 'oferta.programaAcademico:id,nombre', 'oferta.campus:id,nombre', 'situacion:id,nombre']);

        $planes = $this->resolutor->planesDe($matricula);
        $plan = $planes->first();

        return Inertia::render('Finanzas/Cuenta', [
            'matricula' => [
                'id' => $matricula->id,
                'matricula' => $matricula->matricula,
                'nombre' => $matricula->persona?->nombreCompleto(),
                'programa_academico' => $matricula->oferta?->programaAcademico?->nombre,
                'campus' => $matricula->oferta?->campus?->nombre,
                'estatus' => $matricula->estatus,
                'situacion' => $matricula->situacion?->nombre,
                'ingreso' => $matricula->fecha_ingreso?->toDateString(),
            ],
            'cuenta' => $this->estadoCuenta->para($matricula),
            // Se dice de qué plan salen sus cargos, y se advierte cuando no hay
            // ninguno: sin plan de cobro el botón de generar no hará nada, y
            // eso hay que explicarlo antes de que lo aprieten.
            'planCobro' => $plan === null ? null : [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'ciclo' => $plan->ciclo?->nombre,
                'conceptos' => $plan->conceptos->count(),
                'total_planes' => $planes->count(),
            ],
            'metodosPago' => MetodoPago::query()->activos()->orderBy('nombre')
                ->get(['id', 'clave', 'nombre', 'requiere_confirmacion']),
            'situacionesPago' => SituacionPago::query()->orderBy('id')->get(['id', 'clave', 'nombre', 'bloquea']),
            /*
             * Con qué se puede pagar en línea. Vacío = la escuela no tiene
             * ninguna encendida (o la que encendió todavía no sabemos cobrarla),
             * y entonces el botón ni aparece: ofrecer un pago que no se puede
             * completar es peor que no ofrecerlo.
             */
            'pasarelas' => app(Pasarelas::class)->disponibles(),
            /*
             * La otra forma de pagar: transferir a la cuenta de la escuela y
             * subir el comprobante. Sólo las cuentas que sirven para SU programa académico
             * —una escuela suele tener una por programa académico o por nivel— y que
             * tengan a dónde transferir.
             */
            'cuentasBancarias' => CuentaBancaria::paraProgramaAcademico($matricula->oferta?->programa_academico_id)
                ->filter(fn (CuentaBancaria $c) => $c->puedeRecibir())
                ->map(fn (CuentaBancaria $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'banco' => $c->banco,
                    'titular' => $c->titular,
                    'clabe' => $c->clabe,
                    'numero_cuenta' => $c->numero_cuenta,
                    'instrucciones' => $c->instrucciones,
                ])->values(),
            // Lo que ya subió y está esperando: sin esto vuelve a subirlo.
            'comprobantes' => ComprobantePago::query()
                ->where('matricula_oferta_id', $matricula->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (ComprobantePago $c) => [
                    'id' => $c->id,
                    'monto' => (float) $c->monto,
                    'fecha' => $c->fecha_transferencia?->toDateString(),
                    'estado' => $c->estado,
                    'motivo_rechazo' => $c->motivo_rechazo,
                    'subido_en' => $c->created_at?->toDateTimeString(),
                ])->values(),
            'permisos' => [
                'registrarPagos' => $request->user()->can('registrar-pagos'),
                'condonar' => $request->user()->can('condonar-adeudos'),
                'facturar' => $request->user()->can('facturar'),
                'convenios' => $request->user()->can('autorizar-convenios'),
            ],
            /*
             * El convenio VIGENTE de este alumno, si tiene. Se manda aunque no
             * se pueda autorizar: quien cobra en el mostrador necesita ver que
             * hay un acuerdo antes de reclamarle un cargo que ya está
             * reprogramado.
             */
            'convenioVigente' => ($cv = app(ConvenioDePago::class)->vigenteDe($matricula)) === null ? null : [
                'id' => $cv->id,
                'motivo' => $cv->motivo,
                'concepto' => $cv->concepto?->nombre,
                'firmado_en' => $cv->firmado_en?->toDateString(),
                'monto_cubierto' => (float) $cv->monto_cubierto,
                'saldo' => $cv->saldo(),
                'con_atraso' => $cv->tieneAtraso(),
                'parcialidades' => $cv->parcialidades->map(fn (Adeudo $p) => [
                    'id' => $p->id,
                    'vencimiento' => $p->fecha_vencimiento?->toDateString(),
                    'monto' => (float) $p->monto_total,
                    'saldo' => $p->saldo(),
                    'estatus' => $p->estatus,
                ])->values(),
            ],
            'facturas' => Factura::query()
                ->where('matricula_oferta_id', $matricula->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn (Factura $f) => [
                    'id' => $f->id,
                    'uuid' => $f->uuid,
                    'estatus' => $f->estatus,
                    'total' => (float) $f->total,
                    'fecha_timbrado' => $f->fecha_timbrado?->toDateTimeString(),
                ])->values(),
        ]);
    }

    /** Corre el motor de cobro para esta matrícula. Es idempotente: repetirlo no duplica. */
    public function generar(MatriculaOferta $matricula): RedirectResponse
    {
        $resultado = $this->generador->generarPara($matricula);
        $this->recargos->recalcularCartera($matricula->id);

        if ($resultado['planes'] === 0) {
            return back()->with('advertencia', 'Este alumno no tiene ningún plan de cobro vinculado. Vincúlaselo desde el plan.');
        }

        if ($resultado['generados'] === 0) {
            return back()->with('advertencia', 'No había cargos nuevos por emitir: ya estaba todo generado.');
        }

        $aviso = $resultado['generados'] === 1
            ? 'Se generó 1 cargo.'
            : "Se generaron {$resultado['generados']} cargos.";

        return back()->with('exito', $aviso);
    }

    public function registrarPago(Request $request, MatriculaOferta $matricula): RedirectResponse
    {
        $datos = $request->validate([
            'metodo_pago_id' => ['required', Rule::exists('metodos_pago', 'id')],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'adeudo_ids' => ['nullable', 'array'],
            'adeudo_ids.*' => [Rule::exists('adeudos', 'id')],
        ]);

        $metodo = MetodoPago::findOrFail($datos['metodo_pago_id']);

        /*
         * Una lista VACÍA de cargos no es «cubre exactamente estos cero»: es
         * «no elegí ninguno», que el registrador entiende como null y resuelve
         * cubriendo los más vencidos primero.
         *
         * El formulario manda siempre `adeudo_ids`, vacío cuando no se marcó
         * nada, así que sin esta línea el pago entraba por la rama de «respeta
         * lo que eligió quien cobra» con una lista sin nada dentro: se
         * registraba el dinero, no liquidaba ningún cargo y quedaba entero a
         * favor. La pantalla decía «Pago registrado y aplicado» mientras el
         * saldo no se movía.
         */
        $adeudoIds = $datos['adeudo_ids'] ?? null;

        if ($adeudoIds === []) {
            $adeudoIds = null;
        }

        try {
            $pago = $this->registrador->registrar(
                $matricula,
                $metodo,
                (float) $datos['monto'],
                $adeudoIds,
                $datos['referencia'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Un pago que todavía no es dinero se avisa como advertencia, no como
        // éxito: el adeudo sigue abierto y quien cobró tiene que saberlo.
        return $pago->estaCobrado()
            ? back()->with('exito', 'Pago registrado y aplicado.')
            : back()->with(
                'advertencia',
                'Pago registrado como PENDIENTE: '.$metodo->nombre.' requiere confirmación. '
                .'El adeudo no se liquida hasta que se confirme.'
            );
    }

    public function confirmarPago(Pago $pago): RedirectResponse
    {
        $this->registrador->confirmar($pago);

        return back()->with('exito', 'Pago confirmado. Los adeudos que cubre quedaron liquidados.');
    }

    /**
     * El recibo que se le entrega a quien pagó.
     *
     * Sólo de dinero que de verdad entró: imprimir el de un pago PENDIENTE le
     * daría al alumno un papel con el logo de la escuela por una transferencia
     * que todavía no llegó. Responde 404 y no 403 — ese recibo no existe aún,
     * no es que no le toque a quien lo pide.
     */
    public function recibo(Pago $pago, ReciboDeCaja $recibo): SymfonyResponse
    {
        abort_unless($pago->estaCobrado(), 404);

        return $recibo->responder($pago);
    }

    public function revertirPago(Request $request, Pago $pago): RedirectResponse
    {
        $datos = $request->validate([
            'estatus' => ['required', Rule::in([Pago::ESTATUS_FALLIDO, Pago::ESTATUS_REEMBOLSADO])],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->registrador->revertir($pago, $datos['estatus'], $datos['motivo'] ?? null);
        } catch (RuntimeException $e) {
            // Devolver efectivo sin un turno abierto saca billetes de un cajón
            // que nadie va a contar.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('advertencia', 'El pago se marcó como '.$datos['estatus'].' y los adeudos volvieron a quedar abiertos.');
    }

    /**
     * Condonar o cancelar un adeudo. No se borra: el renglón queda con su nuevo
     * estatus y su motivo, porque un cargo que desaparece sin rastro es
     * exactamente lo que después nadie sabe explicar.
     */
    public function resolverAdeudo(Request $request, Adeudo $adeudo): RedirectResponse
    {
        $datos = $request->validate([
            'estatus' => ['required', Rule::in([Adeudo::ESTATUS_CONDONADO, Adeudo::ESTATUS_CANCELADO])],
            // Condonar es regalar dinero de la escuela. Sin motivo, la pregunta
            // "¿quién le perdonó esto?" no tiene respuesta.
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        if (in_array($adeudo->estatus, [Adeudo::ESTATUS_PAGADO], true)) {
            return back()->with('error', 'Un adeudo ya pagado no se condona ni se cancela.');
        }

        $adeudo->update([
            'estatus' => $datos['estatus'],
            // El motivo viaja en la bitácora de la matrícula, que es donde se
            // consulta la historia financiera del alumno.
        ]);

        if ($adeudo->matricula_oferta_id !== null) {
            BitacoraSituacionFinanciera::registrar(
                $adeudo->matricula_oferta_id,
                $this->situacionActualId($adeudo->matricula_oferta_id),
                sprintf('Adeudo #%d %s: %s', $adeudo->id, $datos['estatus'], $datos['motivo']),
            );
        }

        return back()->with('exito', 'El adeudo quedó como '.$datos['estatus'].'.');
    }

    /** Cambia la situación financiera de la matrícula (es lo que bloquea trámites). */
    public function cambiarSituacion(Request $request, MatriculaOferta $matricula): RedirectResponse
    {
        $datos = $request->validate([
            'situacion_id' => ['required', Rule::exists('situaciones_pago', 'id')],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        BitacoraSituacionFinanciera::registrar(
            $matricula->id,
            (int) $datos['situacion_id'],
            $datos['motivo'] ?? null,
        );

        $situacion = SituacionPago::find($datos['situacion_id']);

        return back()->with(
            $situacion?->bloquea ? 'advertencia' : 'exito',
            $situacion?->bloquea
                ? 'La matrícula quedó BLOQUEADA: '.$situacion->nombre.'.'
                : 'Situación financiera actualizada a '.($situacion?->nombre ?? '').'.'
        );
    }

    /**
     * Saldo, vencido y numero de adeudos abiertos por matricula.
     *
     * El armado vive en `App\Services\Finanzas\SaldosDeCartera`: estaba escrito
     * tambien en la tarjeta `CarteraDeLaEscuela` del panel --que enlaza AQUI-- y
     * las dos copias ya habian divergido en si contar los adeudos de aspirante.
     *
     * @param  array<int, int>|null  $matriculaIds
     */
    private function saldosPorMatricula(string $hoy, ?array $matriculaIds = null): Builder
    {
        return app(SaldosDeCartera::class)->porMatricula($hoy, $matriculaIds);
    }

    /** La situación vigente, o la de "al corriente" si nunca se registró una. */
    private function situacionActualId(int $matriculaOfertaId): int
    {
        $vigente = BitacoraSituacionFinanciera::vigenteDe($matriculaOfertaId);

        return $vigente?->situacion_id
            ?? (int) SituacionPago::query()->where('clave', 'corriente')->value('id')
            ?? (int) SituacionPago::query()->value('id');
    }
}
