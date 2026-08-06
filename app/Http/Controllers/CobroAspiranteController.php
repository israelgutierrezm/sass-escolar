<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Admisiones\Aspirante;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Services\RegistradorPago;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * El dinero del aspirante: su ficha, su examen, su inscripción.
 *
 * La base admitía desde el principio un adeudo cuyo titular fuera un aspirante
 * —`adeudos.aspirante_id`, con un CHECK que obliga a exactamente un titular—,
 * el portal del interesado ya se los mostraba y `ReligadorFinanzas` los pasaba a
 * la matrícula al convertirlo. Faltaba lo único que hacía falta para que todo
 * eso sirviera de algo: crear el cargo. Sin esta pantalla el paso «Tu pago» de
 * su portal no se llenaba nunca, salvo insertando la fila a mano en MySQL.
 *
 * ── Por qué NO se usan los planes de cobro ─────────────────────────────────
 * Un plan de cobro cuelga de una oferta y reparte colegiaturas por periodos: es
 * la maquinaria de un alumno inscrito. Al aspirante se le cobra una vez, un
 * concepto suelto, y con frecuencia antes de saber siquiera a qué programa
 * entrará. Forzarlo por el generador de adeudos habría exigido inventarle un
 * plan a alguien que todavía no es alumno.
 *
 * El COBRO en cambio sí es el mismo `RegistradorPago` de siempre: no son dos
 * cajas, es la misma con distinto dueño.
 */
class CobroAspiranteController extends Controller
{
    use AcotaPorCampus;

    public function __construct(private readonly RegistradorPago $registrador) {}

    /**
     * Genera un cargo suelto al aspirante.
     */
    public function generarCargo(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $this->autorizarCampus($request, $aspirante->campus_id);

        $datos = $request->validate([
            'concepto_id' => ['required', Rule::exists('conceptos_pago', 'id')->whereNull('deleted_at')],
            'monto' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            /*
             * Obligatoria: la columna es NOT NULL y con razón.
             *
             * Un cargo sin fecha límite no se puede marcar como vencido nunca,
             * y entonces nada distingue al que lleva tres meses sin pagarse del
             * que se generó ayer. Cuándo vence es una decisión de la escuela,
             * no un dato que el sistema pueda suponer.
             */
            'fecha_vencimiento' => ['required', 'date'],
        ], [], [
            'concepto_id' => 'concepto',
            'fecha_vencimiento' => 'fecha límite',
        ]);

        Adeudo::create([
            'aspirante_id' => $aspirante->id,
            'concepto_id' => $datos['concepto_id'],
            'monto' => $datos['monto'],
            /*
             * `monto_total` se escribe aquí y no se deja calcular.
             *
             * Es el que mira todo lo demás —el saldo del portal, el registrador
             * al aplicar un pago, el estado de cuenta—, y para un cargo suelto
             * de admisión es igual al monto: no hay recargos que aún no han
             * corrido ni becas, que se otorgan por plan de estudios.
             */
            'monto_total' => $datos['monto'],
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => $datos['fecha_vencimiento'],
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);

        return back()->with('exito', 'Cargo generado. Ya le aparece en su portal.');
    }

    /**
     * Cobra. Mismo registrador que el de un alumno: ver la nota de la clase.
     */
    public function registrarPago(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $this->autorizarCampus($request, $aspirante->campus_id);

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
         * cubriendo los más vencidos primero. Mismo cuidado que en la cuenta
         * del alumno, donde este descuido registraba el dinero sin liquidar
         * nada mientras la pantalla decía que sí.
         */
        $adeudoIds = $datos['adeudo_ids'] ?? null;

        if ($adeudoIds === []) {
            $adeudoIds = null;
        }

        try {
            $pago = $this->registrador->registrar(
                $aspirante,
                $metodo,
                (float) $datos['monto'],
                $adeudoIds,
                $datos['referencia'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Un pago que todavía no es dinero se avisa como advertencia: el cargo
        // sigue abierto y quien cobró tiene que saberlo.
        return $pago->estaCobrado()
            ? back()->with('exito', 'Pago registrado y aplicado.')
            : back()->with(
                'advertencia',
                'Pago registrado como PENDIENTE: '.$metodo->nombre.' requiere confirmación. '
                .'El cargo no se liquida hasta que se confirme.'
            );
    }

    /**
     * Cancela un cargo que no debió existir.
     *
     * No se borra: se cancela. Un cargo con pagos aplicados encima no puede
     * desaparecer sin dejar el dinero apuntando a la nada, y el rastro de que
     * se cobró de más es justo lo que alguien va a querer revisar después.
     */
    public function cancelarCargo(Request $request, Adeudo $adeudo): RedirectResponse
    {
        abort_if($adeudo->aspirante_id === null, 404);
        $this->autorizarCampus($request, $adeudo->aspirante?->campus_id);

        // `pagos.estatus` con tabla y todo: la relación va por un pivote que
        // también tiene columnas, y un `estatus` a secas es ambiguo en el SQL.
        if ($adeudo->pagos()->where('pagos.estatus', '!=', Pago::ESTATUS_FALLIDO)->exists()) {
            return back()->with(
                'error',
                'Ese cargo ya tiene pagos aplicados. Reversa el pago primero, desde el estado de cuenta.',
            );
        }

        $adeudo->update(['estatus' => Adeudo::ESTATUS_CANCELADO]);

        return back()->with('exito', 'Cargo cancelado.');
    }

    /**
     * El estado de cuenta del aspirante, para la ficha.
     *
     * Vive aquí y no en `AspiranteController` para que el día que admisiones
     * necesite más —un recibo, un desglose— haya un sitio evidente donde
     * ponerlo. Es el mismo dato que ya arma el portal del interesado, con lo
     * que un administrador necesita de más: quién cobró y con qué método.
     *
     * @return array<string, mixed>
     */
    public static function estadoDeCuenta(Aspirante $aspirante): array
    {
        $adeudos = Adeudo::query()
            ->with('concepto:id,nombre')
            ->deAspirante($aspirante->id)
            ->orderBy('fecha_vencimiento')
            ->orderBy('id')
            ->get();

        $pagos = Pago::query()
            ->with('metodoPago:id,nombre')
            ->where('aspirante_id', $aspirante->id)
            ->orderByDesc('momento')
            ->get();

        return [
            'cargos' => $adeudos->map(fn (Adeudo $a) => [
                'id' => $a->id,
                'concepto' => $a->concepto?->nombre,
                'total' => (float) $a->monto_total,
                'saldo' => (float) $a->saldo(),
                'vencimiento' => $a->fecha_vencimiento?->toDateString(),
                'vencido' => $a->estaVencido(),
                'estatus' => $a->estatus,
            ])->all(),
            'pagos' => $pagos->map(fn (Pago $p) => [
                'id' => $p->id,
                'monto' => (float) $p->monto,
                'metodo' => $p->metodoPago?->nombre,
                'referencia' => $p->referencia,
                'estatus' => $p->estatus,
                'cobrado' => $p->estaCobrado(),
                'momento' => $p->momento?->toDateTimeString(),
            ])->all(),
            'saldo' => (float) $adeudos->sum(fn (Adeudo $a) => $a->saldo()),
            'conceptos' => ConceptoPago::query()->orderBy('nombre')->get(['id', 'nombre']),
            'metodos' => MetodoPago::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
