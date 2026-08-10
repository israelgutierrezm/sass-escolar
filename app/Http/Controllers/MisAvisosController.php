<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoAdjunto;
use App\Services\Plataforma\AvisosDeUsuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los avisos vistos desde el lado de quien los recibe.
 *
 * Sin permiso que valga: recibir un aviso no es una facultad que se otorgue,
 * es la contraparte de que alguien lo haya dirigido. Lo único que decide qué
 * ve cada quien son los destinos del aviso, resueltos contra su persona.
 */
class MisAvisosController extends Controller
{
    public function __construct(private readonly AvisosDeUsuario $avisos) {}

    public function index(Request $request): Response
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        /*
         * `lista` y no `avisos`: ese nombre ya es del share.
         *
         * Una prop de página con el mismo nombre PISA la compartida, y aquí eso
         * dejaba sin datos —sólo en esta pantalla— a la campana y al modal del
         * aviso crítico, que leen `avisos.sin_leer` y `avisos.pendientes`. Un
         * aviso que bloquea en todas partes menos en una es peor que no tenerlo.
         */
        return Inertia::render('Avisos/Mios', [
            'lista' => $this->avisos->todos($usuario),
        ]);
    }

    /** «Lo leí»: lo que quita de en medio un crítico y apaga un importante. */
    public function confirmar(Request $request, Aviso $aviso): RedirectResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        /*
         * Si no le tocaba, se responde 404 y no 403.
         *
         * Un 403 confirmaría que el aviso existe; para alguien a quien no iba
         * dirigido, ese aviso sencillamente no está ahí.
         */
        abort_unless($this->avisos->confirmar($usuario, $aviso), 404);

        return back(303);
    }

    /**
     * Sirve un archivo adjunto.
     *
     * Sólo a quien el aviso le llega. Que la dirección lleve un uuid impide
     * adivinarla contando, pero un enlace se reenvía y se pega en un chat: lo
     * que de verdad protege el archivo es comprobar, en cada descarga, que
     * quien la pide es destinatario del aviso al que pertenece.
     */
    public function adjunto(Request $request, string $uuid): StreamedResponse
    {
        $adjunto = AvisoAdjunto::query()->where('uuid', $uuid)->firstOrFail();

        /** @var Usuario $usuario */
        $usuario = $request->user();

        // 404 y no 403: para quien no es destinatario, ese archivo no está ahí.
        abort_unless($this->avisos->leLlega($usuario, $adjunto->aviso), 404);
        abort_unless($adjunto->ruta !== null && Storage::disk('local')->exists($adjunto->ruta), 404);

        return Storage::disk('local')->response(
            $adjunto->ruta,
            $adjunto->nombre_original,
            // `inline` para que un PDF se abra en el navegador en vez de
            // descargarse: la mitad de los adjuntos se consultan una vez y no
            // hay por qué dejarlos en la carpeta de descargas de nadie.
            ['Content-Type' => $adjunto->mime ?? 'application/octet-stream', 'Content-Disposition' => 'inline'],
        );
    }
}
