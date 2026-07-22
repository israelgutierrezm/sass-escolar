<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\ExpedienteDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expediente documental del aspirante.
 *
 * Los archivos van al disco `local`, que stancl/tenancy sufija por escuela
 * (FilesystemTenancyBootstrapper): cada tenant escribe en su propia carpeta y
 * no puede leer la de otro. Es almacenamiento PRIVADO —son datos personales
 * sujetos a la LFPDPPP—, por eso se sirven por una ruta autenticada y nunca
 * desde public/.
 */
class ExpedienteAspiranteController extends Controller
{
    private const CARPETA = 'expedientes';

    public function store(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $datos = $request->validate([
            'documento_id' => ['required', 'integer', Rule::exists('documentos_requeridos', 'id')->whereNull('deleted_at')],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'copia_certificada' => ['boolean'],
            'documento_fisico' => ['boolean'],
        ]);

        $ruta = $request->file('archivo')->store(
            sprintf('%s/%d', self::CARPETA, $aspirante->id),
            'local',
        );

        ExpedienteDocumento::updateOrCreate(
            [
                'aspirante_id' => $aspirante->id,
                'documento_id' => $datos['documento_id'],
            ],
            [
                'carrera_id' => $aspirante->ofertaInteres?->carrera_id,
                'url' => $ruta,
                'estado_documento_id' => EstadoDocumento::query()->where('clave', 'pendiente')->value('id'),
                'copia_certificada' => $datos['copia_certificada'] ?? false,
                'documento_fisico' => $datos['documento_fisico'] ?? false,
            ],
        );

        return back()->with('exito', 'Documento cargado. Queda pendiente de revisión.');
    }

    /**
     * Revisión del documento: aceptarlo o rechazarlo. Requiere el permiso
     * `validar-expediente`, que no todos los administrativos tienen.
     */
    public function actualizarEstado(Request $request, Aspirante $aspirante, ExpedienteDocumento $documento): RedirectResponse
    {
        abort_unless($documento->aspirante_id === $aspirante->id, 404);

        $datos = $request->validate([
            'estado_documento_id' => ['required', 'integer', Rule::exists('estados_documento', 'id')->whereNull('deleted_at')],
            'observaciones' => ['nullable', 'string', 'max:255'],
        ]);

        $rechazado = EstadoDocumento::query()
            ->whereKey($datos['estado_documento_id'])
            ->value('clave') === 'rechazado';

        // Rechazar sin motivo obliga al aspirante a adivinar qué corregir, y
        // ahora él ve esa pantalla. Es la misma regla que ya rige el expediente
        // del docente.
        if ($rechazado && blank($datos['observaciones'] ?? null)) {
            return back()->with('error', 'Para rechazar un documento hay que decir por qué: es lo único que el aspirante va a leer.');
        }

        $documento->update($datos);

        return back()->with('exito', 'Estado del documento actualizado.');
    }

    /**
     * Descarga autenticada. El archivo nunca es accesible por URL directa.
     */
    public function descargar(Aspirante $aspirante, ExpedienteDocumento $documento): StreamedResponse
    {
        abort_unless($documento->aspirante_id === $aspirante->id, 404);
        abort_unless(Storage::disk('local')->exists($documento->url), 404);

        return Storage::disk('local')->download(
            $documento->url,
            sprintf('%s - %s', $documento->documento->nombre, $aspirante->persona->nombreCompleto()),
        );
    }
}
