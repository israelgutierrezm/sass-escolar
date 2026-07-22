<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Foto de perfil de una persona.
 *
 * Un solo punto para toda la escuela: la usan la ficha del alumno, la del
 * docente y el expediente que cada quien mantiene de sí mismo. Tener un
 * endpoint por rol habría multiplicado el mismo control de acceso.
 *
 * Quién puede cambiarla: uno mismo siempre, y quien tenga permiso para editar a
 * esa clase de persona. Quién puede verla: cualquiera que pueda ver su ficha —
 * se sirve por ruta autenticada porque el archivo vive en el disco privado.
 */
class FotoPersonaController extends Controller
{
    private const CARPETA = 'fotos';

    public function actualizar(Request $request, Persona $persona): RedirectResponse
    {
        $this->autorizar($request, $persona);

        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'foto.image' => 'El archivo debe ser una imagen.',
            'foto.max' => 'La foto no puede pasar de 2 MB.',
        ]);

        $anterior = $persona->foto_url;

        $ruta = $request->file('foto')->store(self::CARPETA, 'local');

        $persona->update(['foto_url' => $ruta]);

        // La anterior se borra: se reemplazó, y conservarla solo acumula datos
        // personales que ya nadie va a ver.
        if ($anterior !== null && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }

        return back()->with('exito', 'Foto actualizada.');
    }

    public function mostrar(Request $request, Persona $persona): StreamedResponse
    {
        abort_if($persona->foto_url === null, 404);
        abort_unless(Storage::disk('local')->exists($persona->foto_url), 404);

        // `response()->file` sirve la imagen en línea para que el <img> la
        // pinte, en vez de forzar una descarga.
        return Storage::disk('local')->response($persona->foto_url);
    }

    public function eliminar(Request $request, Persona $persona): RedirectResponse
    {
        $this->autorizar($request, $persona);

        if ($persona->foto_url !== null) {
            Storage::disk('local')->delete($persona->foto_url);
            $persona->update(['foto_url' => null]);
        }

        return back()->with('exito', 'Foto eliminada.');
    }

    /**
     * Uno mismo, o quien administre a esa clase de persona. Se comprueba contra
     * lo que la persona ES —alumno, docente— y no contra un permiso genérico de
     * "editar personas", que no distingue a quién.
     */
    private function autorizar(Request $request, Persona $persona): void
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        if ($usuario->persona_id === $persona->id) {
            return;
        }

        $esAlumno = $persona->matriculas()->exists();
        $esDocente = $persona->docente()->exists();

        $puede = ($esAlumno && $usuario->can('editar-alumnos'))
            || ($esDocente && $usuario->can('gestionar-docentes'))
            || $usuario->can('editar-personas');

        if (! $puede) {
            throw new AccessDeniedHttpException('No puedes cambiar la foto de esa persona.');
        }
    }
}
