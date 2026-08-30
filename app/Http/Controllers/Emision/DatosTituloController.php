<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Http\Controllers\Controller;
use App\Models\Academico\NivelEstudio;
use App\Models\Admisiones\MatriculaOferta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Captura administrativa de los datos del título por programa académico-alumno (una fila por
 * `matricula_oferta`). Son tres formularios de esquema fijo —modalidad de
 * titulación, servicio social y antecedente— que alimentan el XML del título
 * electrónico y viven como pestañas del expediente del programa académico. Cada uno hace
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
            'fecha_terminacion_programa_academico' => ['nullable', 'date'],
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
        $validador = validator($request->all(), [
            'institucion_procedencia' => ['nullable', 'string', 'max:255'],
            'nivel_antecedente_id' => ['nullable', 'integer', 'exists:niveles_estudio,id'],
            'entidad_federativa_id' => ['nullable', 'integer'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_terminacion' => ['nullable', 'date'],
            'no_cedula' => ['nullable', 'string', 'max:60'],
        ]);

        // La cédula es obligatoria si el antecedente es Licenciatura (2) o
        // Maestría (1): son estudios que ya debieron generar cédula profesional.
        $validador->after(function ($v) use ($request) {
            if ($this->cedulaObligatoria($request->input('nivel_antecedente_id')) && blank($request->input('no_cedula'))) {
                $v->errors()->add('no_cedula', 'El número de cédula es obligatorio cuando el antecedente es Licenciatura o Maestría.');
            }
        });

        $alumno->tituloAntecedente()->updateOrCreate([], $validador->validate());

        return back()->with('exito', 'Datos de antecedente guardados.');
    }

    /** ¿El nivel antecedente exige cédula? (Licenciatura o Maestría.) */
    private function cedulaObligatoria(mixed $nivelId): bool
    {
        if (blank($nivelId)) {
            return false;
        }

        $idTipo = NivelEstudio::withTrashed()->whereKey($nivelId)->value('identificador_titulo');

        return in_array((int) $idTipo, [1, 2], true); // 1 = Maestría, 2 = Licenciatura
    }
}
