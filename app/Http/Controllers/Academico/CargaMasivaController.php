<?php

declare(strict_types=1);

namespace App\Http\Controllers\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\PlanEstudio;
use App\Services\Excel\ImportadorAcademico;
use App\Services\Excel\PlantillaAcademica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Carga masiva del catálogo académico por Excel: descarga de plantillas (con
 * validaciones tomadas de los catálogos de la escuela) e importación con
 * validación fila por fila. Nada se crea si el archivo trae errores.
 */
class CargaMasivaController extends Controller
{
    public function plantilla(PlantillaAcademica $plantillas): BinaryFileResponse
    {
        return response()->download($plantillas->completa(), 'plantilla-carga-academica.xlsx')->deleteFileAfterSend();
    }

    public function importar(Request $request, ImportadorAcademico $importador): RedirectResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'max:5120']]);

        try {
            $resultado = $importador->completo($request->file('archivo')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo leer el archivo. Sube el .xlsx de la plantilla sin cambiar su estructura.');
        }

        return $this->responder($resultado, function (array $r) {
            return "Carga completa: {$r['campus']} campus, {$r['carreras']} carreras, {$r['planes']} planes, {$r['asignaturas']} asignaturas.";
        });
    }

    public function plantillaPlan(PlanEstudio $plan, PlantillaAcademica $plantillas): BinaryFileResponse
    {
        return response()->download($plantillas->asignaturasDePlan($plan->nombre), 'plantilla-asignaturas.xlsx')->deleteFileAfterSend();
    }

    public function importarPlan(Request $request, PlanEstudio $plan, ImportadorAcademico $importador): RedirectResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'max:5120']]);

        try {
            $resultado = $importador->asignaturasDePlan($request->file('archivo')->getRealPath(), $plan);
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo leer el archivo. Sube el .xlsx de la plantilla sin cambiar su estructura.');
        }

        return $this->responder($resultado, function (array $r) {
            return "Se cargaron {$r['asignaturas']} asignaturas al plan.";
        });
    }

    /**
     * @param  array{errores: array<int, mixed>, resumen: array<string, int>}  $resultado
     */
    private function responder(array $resultado, callable $mensaje): RedirectResponse
    {
        if ($resultado['errores'] !== []) {
            return back()
                ->with('error', 'El archivo tiene errores; corrígelos y vuelve a subirlo.')
                ->with('erroresCarga', $resultado['errores']);
        }

        return back()->with('exito', $mensaje($resultado['resumen']));
    }
}
