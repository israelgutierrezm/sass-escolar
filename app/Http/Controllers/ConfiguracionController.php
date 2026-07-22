<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las reglas de operación de la escuela.
 *
 * Están pensadas para fijarse ANTES de que existan registros, que es cuando
 * salen gratis. Después siguen siendo editables —una escuela puede cambiar de
 * criterio a media operación y tiene derecho— pero la pantalla dice cuánto hay
 * hecho ya bajo la regla anterior, porque **cambiar un límite no reevalúa el
 * pasado**: quien ya lleva tres recursamientos no se da de baja solo porque
 * hoy el máximo pase a dos.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private readonly Ajustes $ajustes) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Plataforma/Configuracion', [
            'grupos' => $this->ajustes->paraPantalla(),
            'huella' => $this->huella(),
            'puedeEditar' => $request->user()->can('editar-configuracion'),
        ]);
    }

    public function actualizar(Request $request): RedirectResponse
    {
        $datos = $request->validate(['ajustes' => ['required', 'array']]);

        // Los valores se validan contra el catálogo: los rangos declarados son
        // la verdad, y un entero fuera de rango convertiría una regla en una
        // que nadie puede cumplir.
        foreach ($datos['ajustes'] as $clave => $valor) {
            $ajuste = CatalogoAjustes::buscar((string) $clave);

            if ($ajuste === null) {
                continue;
            }

            if ($ajuste->tipo === 'entero') {
                $n = (int) $valor;

                if (($ajuste->min !== null && $n < $ajuste->min) || ($ajuste->max !== null && $n > $ajuste->max)) {
                    return back()->with('error', "«{$ajuste->etiqueta}» debe estar entre {$ajuste->min} y {$ajuste->max}.");
                }
            }

            if ($ajuste->tipo === 'seleccion' && ! array_key_exists((string) $valor, $ajuste->opciones)) {
                return back()->with('error', "«{$ajuste->etiqueta}» tiene un valor que no está entre sus opciones.");
            }
        }

        $this->ajustes->guardar($datos['ajustes']);

        return back()->with(
            'advertencia',
            'Reglas guardadas. Aplican de aquí en adelante: lo que ya ocurrió bajo las reglas anteriores no se reevalúa.'
        );
    }

    /**
     * Cuánta operación hay ya hecha. No bloquea nada —la escuela manda— pero
     * es la diferencia entre configurar en blanco y configurar encima de un
     * ciclo en curso, y quien lo hace merece saber en cuál de las dos está.
     *
     * @return array<string, int>
     */
    private function huella(): array
    {
        return [
            'matriculas' => MatriculaOferta::query()->count(),
            'inscripciones' => Inscripcion::query()->count(),
            'kardex' => Historial::query()->count(),
            'personas_con_varias_matriculas' => (int) DB::table('matricula_oferta')
                ->whereNull('deleted_at')
                ->select('persona_id')
                ->groupBy('persona_id')
                ->havingRaw('count(*) > 1')
                ->get()
                ->count(),
        ];
    }
}
