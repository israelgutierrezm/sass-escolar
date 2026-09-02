<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PrioridadAviso;
use App\Models\Finanzas\RecordatorioCobranza;
use App\Models\Finanzas\ReglaRecordatorioCobranza;
use App\Services\Finanzas\RecordatorioDeCobranza;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La escalera de recordatorios de cobranza.
 *
 * ── Sin permiso nuevo, a propósito ─────────────────────────────────────────
 * Va con `gestionar-planes-cobro`, el mismo de los planes, los descuentos y los
 * conceptos: esto es CONFIGURAR el cobro, del mismo orden que ponerle monto a
 * una colegiatura. Mandar los avisos no lo hace nadie desde una pantalla —lo
 * hace el comando de las siete—, así que no hay un acto que separar. Un permiso
 * más sin un acto que proteger es una llave que la escuela tiene que decidir a
 * quién dar sin saber para qué.
 *
 * ── La vista previa es la mitad de la pantalla ─────────────────────────────
 * Antes de encender un peldaño hay que poder ver a cuánta gente le va a llegar
 * y con qué texto. Sin eso, la única forma de saberlo es mandarlo — y un
 * recordatorio mal calibrado sale a toda la escuela de una vez.
 */
class CobranzaController extends Controller
{
    public function __construct(private readonly RecordatorioDeCobranza $recordatorio) {}

    public function index(): Response
    {
        // El modo seco del comando, dentro de la pantalla: a quién le llegaría
        // HOY si se corriera ahora.
        $previo = $this->recordatorio->correr(null, seco: true);

        return Inertia::render('Finanzas/Cobranza/Index', [
            'reglas' => ReglaRecordatorioCobranza::query()
                ->enEscalera()
                ->get()
                ->map(fn (ReglaRecordatorioCobranza $r) => [
                    'id' => $r->id,
                    'nombre' => $r->nombre,
                    'dias' => $r->dias,
                    'cuando' => $r->cuando(),
                    'titulo' => $r->titulo,
                    'cuerpo' => $r->cuerpo,
                    'prioridad' => $r->prioridad,
                    'dias_vigente' => $r->dias_vigente,
                    'activo' => $r->activo,
                    // Cuántas veces se ha mandado ya: es lo que distingue un
                    // peldaño que nadie usa de uno que está trabajando.
                    'emitidos' => RecordatorioCobranza::query()->where('regla_id', $r->id)->count(),
                ]),
            'prioridades' => PrioridadAviso::paraSelector(),
            'tokens' => ReglaRecordatorioCobranza::tokens(),
            'previo' => $previo['detalle'],
            'hayEncendidas' => ReglaRecordatorioCobranza::query()->activas()->exists(),
        ]);
    }

    public function guardar(Request $peticion, ?ReglaRecordatorioCobranza $regla = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'dias' => [
                'required', 'integer', 'min:-60', 'max:365',
                // Dos peldaños el mismo día se pisarían: el más severo se
                // llevaría el texto y el otro no se mandaría nunca, sin que
                // nada lo dijera.
                Rule::unique('reglas_recordatorio_cobranza', 'dias')
                    ->ignore($regla?->id)
                    ->whereNull('deleted_at'),
            ],
            'titulo' => ['required', 'string', 'max:180'],
            'cuerpo' => ['required', 'string', 'max:2000'],
            'prioridad' => ['required', Rule::in(array_column(PrioridadAviso::cases(), 'value'))],
            'dias_vigente' => ['required', 'integer', 'min:1', 'max:120'],
            'activo' => ['required', 'boolean'],
        ], [
            'dias.unique' => 'Ya hay un peldaño para ese día. Dos el mismo día se pisarían y uno no se mandaría nunca.',
        ]);

        // Validar no convierte: la casilla llega como cadena.
        $datos['activo'] = $peticion->boolean('activo');
        $datos['dias'] = (int) $datos['dias'];
        $datos['dias_vigente'] = (int) $datos['dias_vigente'];

        if ($regla === null) {
            ReglaRecordatorioCobranza::create($datos);

            return back(303)->with('exito', 'Peldaño creado.');
        }

        $regla->update($datos);

        return back(303)->with('exito', 'Peldaño actualizado.');
    }

    /**
     * Retira un peldaño.
     *
     * Se da de BAJA LÓGICA y no se borra: sus recordatorios ya emitidos lo
     * nombran, y con la fila fuera el rastro se quedaría apuntando a la nada —
     * el comando no volvería a avisar por él, pero nadie podría explicar qué
     * decía el mensaje que la familia recibió.
     */
    public function eliminar(ReglaRecordatorioCobranza $regla): RedirectResponse
    {
        $regla->delete();

        return back(303)->with('exito', 'Peldaño retirado. Los recordatorios que ya salieron se conservan.');
    }
}
