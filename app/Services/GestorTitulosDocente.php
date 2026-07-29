<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ControlEscolar\TituloDocente;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Alta/baja de los títulos (grados) de un docente, con su documento en el disco
 * privado. Se comparte entre la administración (control escolar) y el
 * autoservicio del propio docente, para que ambos validen y guarden igual.
 */
class GestorTitulosDocente
{
    private const CARPETA = 'titulos-docente';

    /**
     * @return array<string, mixed>
     */
    public function reglas(): array
    {
        return [
            'grado' => ['required', 'string', 'max:60'],
            'titulo_obtenido' => ['required', 'string', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:30'],
            'institucion' => ['nullable', 'string', 'max:255'],
            'anio' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            'archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function agregar(int $personaId, array $datos, ?UploadedFile $archivo): TituloDocente
    {
        return TituloDocente::create([
            'persona_id' => $personaId,
            'grado' => $datos['grado'],
            'titulo_obtenido' => $datos['titulo_obtenido'],
            'cedula' => $datos['cedula'] ?? null,
            'institucion' => $datos['institucion'] ?? null,
            'anio' => $datos['anio'] ?? null,
            'archivo_url' => $archivo?->store(self::CARPETA, 'local'),
        ]);
    }

    public function quitar(TituloDocente $titulo): void
    {
        if ($titulo->archivo_url !== null) {
            Storage::disk('local')->delete($titulo->archivo_url);
        }

        $titulo->delete();
    }

    public function descargar(TituloDocente $titulo): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_if($titulo->archivo_url === null, 404);
        abort_unless(Storage::disk('local')->exists($titulo->archivo_url), 404);

        return Storage::disk('local')->response($titulo->archivo_url);
    }
}
