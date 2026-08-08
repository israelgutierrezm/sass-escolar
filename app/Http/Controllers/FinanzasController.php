<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;

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
use App\Models\Identidad\TutorAlumno;
use App\Services\CalculadorRecargos;
use App\Services\EstadoCuenta;
use App\Services\GeneradorAdeudos;
use App\Services\Pagos\Pasarelas;
use App\Services\RegistradorPago;
use App\Services\ResolutorPlanCobro;
use App\Support\CatalogoPermisos;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.carrera:id,nombre', 'oferta.campus:id,nombre'])
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
                'carrera' => $m->oferta?->carrera?->nombre,
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

        $matricula->load(['persona', 'oferta.carrera:id,nombre', 'oferta.campus:id,nombre', 'situacion:id,nombre']);

        $planes = $this->resolutor->planesDe($matricula);
        $plan = $planes->first();

        return Inertia::render('Finanzas/Cuenta', [
            'matricula' => [
                'id' => $matricula->id,
                'matricula' => $matricula->matricula,
                'nombre' => $matricula->persona?->nombreCompleto(),
                'carrera' => $matricula->oferta?->carrera?->nombre,
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
             * subir el comprobante. Sólo las cuentas que sirven para SU carrera
             * —una escuela suele tener una por carrera o por nivel— y que
             * tengan a dónde transferir.
             */
            'cuentasBancarias' => CuentaBancaria::paraCarrera($matricula->oferta?->carrera_id)
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

    public function revertirPago(Request $request, Pago $pago): RedirectResponse
    {
        $datos = $request->validate([
            'estatus' => ['required', Rule::in([Pago::ESTATUS_FALLIDO, Pago::ESTATUS_REEMBOLSADO])],
        ]);

        $this->registrador->revertir($pago, $datos['estatus']);

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
     * Saldo, vencido y número de adeudos abiertos por matrícula, en una sola
     * subconsulta agregada.
     *
     * Lo aplicado se calcula aparte y se une por LEFT JOIN porque un adeudo
     * puede tener varios pagos: sumarlo en el mismo GROUP BY multiplicaría el
     * `monto_total` por cuantos pagos tenga encima.
     */
    private function saldosPorMatricula(string $hoy, ?array $matriculaIds = null): Builder
    {
        $aplicados = DB::table('pago_adeudo as pa')
            ->join('pagos as p', 'p.id', '=', 'pa.pago_id')
            ->whereNull('pa.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.estatus', Pago::ESTATUS_COMPLETADO)
            ->groupBy('pa.adeudo_id')
            ->select('pa.adeudo_id', DB::raw('sum(pa.monto_aplicado) as aplicado'));

        return DB::table('adeudos as a')
            ->leftJoinSub($aplicados, 'ap', 'ap.adeudo_id', '=', 'a.id')
            ->whereNull('a.deleted_at')
            ->whereNotNull('a.matricula_oferta_id')
            ->whereIn('a.estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL])
            /*
             * Para el encabezado de quien no ve toda la cartera: se acota a las
             * matrículas que puede ver, así el «saldo» y el «vencido» de arriba
             * son sólo de ellas.
             *
             * Recibe la LISTA de matrículas y no la persona titular, que era lo
             * que hacía antes: un padre de familia no es titular de la
             * matrícula de su hijo, así que por titular le habría seguido
             * sumando la cartera completa de la escuela debajo de una tabla ya
             * acotada —el número más visible de la pantalla, y el más fácil de
             * dar por bueno—.
             */
            ->when($matriculaIds !== null, fn ($q) => $q->whereIn('a.matricula_oferta_id', $matriculaIds))
            ->groupBy('a.matricula_oferta_id')
            ->select('a.matricula_oferta_id')
            ->selectRaw('sum(a.monto_total - coalesce(ap.aplicado, 0)) as saldo')
            // La fecha va como binding y no interpolada: es de `now()` y no del
            // usuario, pero una consulta cruda con fechas pegadas a mano es la
            // que alguien copia mañana para un filtro que sí viene de fuera.
            ->selectRaw(
                'sum(case when a.fecha_vencimiento < ? then a.monto_total - coalesce(ap.aplicado, 0) else 0 end) as vencido',
                [$hoy]
            )
            ->selectRaw('count(*) as adeudos');
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
