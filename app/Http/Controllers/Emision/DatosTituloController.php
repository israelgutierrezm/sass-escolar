<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Captura administrativa de los datos del título por carrera-alumno (una fila por
 * `matricula_oferta`). Son tres formularios de esquema fijo —modalidad de
 * titulación, servicio social y antecedente— que alimentan el XML del título
 * electrónico y viven como pestañas del expediente de la carrera. Cada uno hace
 * upsert de su fila; no son los formularios dinámicos de admisiones.
 */
class DatosTituloController extends Controller
{
    public function modalidad(Request $request, MatriculaOferta $alumno): RedirectResponse
    {
        $datos = $request->validate([
            'modalidad_titulacion_id' => ['nullable', 'integer', 'exists:modalidades_titulacion,id'],
            'fecha_expedicion' => ['nullable', 'date'],
            'fecha_examen_profesional' => ['nullable', 'date'],
            'fecha_exencion_examen' => ['nullable', 'date'],
            'fecha_terminacion_carrera' => ['nullable', 'date'],
            'entidad_federativa_id' => ['nullable', 'integer'],
        ]);

        $alumno->tituloModalidad()->updateOrCreate([], $datos);

        return back()->with('exito', 'Datos de modalidad de titulación guardados.');
    }

    public function servicioSocial(Request $request, MatriculaOferta $alumno): RedirectResponse
    {
        $datos = $request->validate([
            'cumplio_servicio_social' => ['nullable', 'boolean'],
            'fundamento_legal_ss_id' => ['nullable', 'integer', 'exists:fundamentos_legales_servicio_social,id'],
        ]);

        $alumno->tituloServicioSocial()->updateOrCreate([], $datos);

        return back()->with('exito', 'Datos de servicio social guardados.');
    }

    public function antecedente(Request $request, MatriculaOferta $alumno): RedirectResponse
    {
        $datos = $request->validate([
            'institucion_procedencia' => ['nullable', 'string', 'max:255'],
            'nivel_antecedente_id' => ['nullable', 'integer', 'exists:niveles_estudio,id'],
            'entidad_federativa_id' => ['nullable', 'integer'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_terminacion' => ['nullable', 'date'],
            'no_cedula' => ['nullable', 'string', 'max:60'],
        ]);

        $alumno->tituloAntecedente()->updateOrCreate([], $datos);

        return back()->with('exito', 'Datos de antecedente guardados.');
    }
}
