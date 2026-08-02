<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use App\Models\Lms\ImagenContenido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las imágenes que se pegan dentro del material de una lección.
 *
 * ── El problema que resuelve ───────────────────────────────────────────────
 * El editor emite HTML, y una imagen dentro del HTML necesita una dirección.
 * Sin esto, al docente sólo le quedaba pegar la de otro sitio: enlaces que se
 * caen a mitad del semestre, contenido ajeno que puede cambiar debajo, y la
 * dirección de la escuela filtrada a un servidor de terceros cada vez que un
 * alumno abre la lección.
 *
 * Así que se sube aquí. El archivo va al disco PRIVADO, como el resto de los
 * adjuntos del sistema, y sale por una ruta que exige sesión.
 *
 * ── Por qué el uuid ────────────────────────────────────────────────────────
 * La dirección queda escrita dentro del HTML y se sirve durante todo el curso.
 * Con un id que se cuenta, quien pidiera el 1, el 2, el 3… se llevaría el
 * material entero de la escuela; con el uuid hay que conocerlo para pedirlo.
 */
class ImagenContenidoController extends Controller
{
    /**
     * Recibe la imagen y devuelve su dirección.
     *
     * Responde JSON y no una redirección de Inertia porque quien llama es el
     * editor a media escritura: necesita la URL para insertarla ahí mismo, sin
     * recargar la página y perder lo que se lleva escrito.
     */
    public function subir(Request $request): JsonResponse
    {
        $request->validate([
            /*
             * SVG queda fuera aunque sea una imagen: es XML y admite `<script>`
             * dentro. Servido desde el propio dominio, se ejecutaría con la
             * sesión de quien lo abre. Lo demás son formatos que el navegador
             * pinta y no interpreta.
             */
            'imagen' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ], [], ['imagen' => 'imagen']);

        $archivo = $request->file('imagen');

        /** @var Usuario $usuario */
        $usuario = $request->user();

        $imagen = ImagenContenido::create([
            'ruta' => $archivo->store('lms/contenido', 'local'),
            'nombre_original' => $archivo->getClientOriginalName(),
            'mime' => $archivo->getMimeType(),
            'tamano' => $archivo->getSize(),
            'subida_por' => $usuario->id,
        ]);

        return response()->json([
            'url' => $imagen->url(),
            'nombre' => $imagen->nombre_original,
        ]);
    }

    /**
     * Sirve la imagen a quien tenga sesión en la escuela.
     *
     * No se comprueba la inscripción a propósito: la misma imagen puede estar
     * pegada en la lección de un grupo, en la plantilla del plan y en el
     * material que el docente reusa el semestre siguiente. Atarla a una materia
     * exigiría rastrear en qué HTML aparece —y quedaría rota en cuanto alguien
     * copiara el bloque a otra—. Es material didáctico de la propia escuela,
     * detrás de sesión y con una dirección que no se adivina.
     */
    public function ver(string $uuid): StreamedResponse
    {
        $imagen = ImagenContenido::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless(Storage::disk('local')->exists($imagen->ruta), 404);

        return Storage::disk('local')->response($imagen->ruta, $imagen->nombre_original, [
            'Content-Type' => $imagen->mime,
            // Es contenido de clase que no cambia: que el navegador la guarde
            // en vez de pedirla otra vez en cada lección donde aparezca.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
