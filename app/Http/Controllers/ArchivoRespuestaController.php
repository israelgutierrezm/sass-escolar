<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Respuesta;
use App\Services\Lms\SalaDeMateria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Descarga del archivo que un alumno subió como respuesta de un examen.
 *
 * Ruta única para los dos lados —el alumno revisando lo que entregó y el docente
 * calificándolo— porque el permiso no es lo que decide: decide la PERTENENCIA.
 * Es tuyo, o eres docente de esa materia. Dos rutas con dos `can:` distintos
 * habrían sido dos copias de la misma comprobación.
 *
 * Sin esto, el reactivo de tipo archivo se podía contestar pero no revisar: el
 * docente veía el nombre del archivo y no había cómo abrirlo.
 */
class ArchivoRespuestaController extends Controller
{
    public function __construct(private readonly SalaDeMateria $sala) {}

    public function __invoke(Request $request, Respuesta $respuesta)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $personaId = $usuario->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');

        $intento = $respuesta->intento;
        $asignaturaGrupoId = $intento?->examen?->actividad?->curso?->asignatura_grupo_id;

        abort_if($asignaturaGrupoId === null, 404);

        $materia = AsignaturaGrupo::findOrFail($asignaturaGrupoId);

        $mio = Inscripcion::query()
            ->whereKey($intento->inscripcion_id)
            ->whereIn('matricula_oferta_id', $usuario->persona?->matriculas()->pluck('matricula_oferta.id') ?? collect())
            ->exists();

        if (! $mio && ! $this->sala->esDocente($materia, $personaId) && ! $usuario->can('abrir-grupos')) {
            throw new AccessDeniedHttpException('Ese archivo no es tuyo.');
        }

        $archivo = $respuesta->valor['v'] ?? null;

        // Un reactivo de otro tipo no tiene archivo: se responde 404 igual que
        // si no existiera, sin decir qué clase de reactivo es.
        abort_if(! is_array($archivo) || blank($archivo['ruta'] ?? null), 404);

        $ruta = (string) $archivo['ruta'];

        // El archivo servido tiene que vivir en la carpeta de ESTE intento. Sin
        // esto, una respuesta con una `ruta` fabricada por el cliente —el endpoint
        // de guardar acepta cualquier `valor`— haría que esta descarga entregara
        // cualquier archivo del disco privado del tenant (comprobantes de pago,
        // XML de títulos, la entrega de otro alumno). La subida legítima siempre
        // guarda en `examenes/{intento}/…`, así que quien envenene su propia
        // respuesta sólo puede apuntar, como mucho, a lo que él mismo subió.
        $carpeta = "examenes/{$intento->id}/";
        abort_unless(str_starts_with($ruta, $carpeta) && ! str_contains($ruta, '..'), 404);

        abort_unless(Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->download(
            $ruta,
            $archivo['nombre'] ?? 'respuesta',
        );
    }
}
