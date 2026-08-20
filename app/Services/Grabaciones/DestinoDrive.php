<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Lms\DestinoGrabacion;
use App\Services\Google\TokenDeServicio;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Drive, con la misma cuenta de servicio que ya usa Meet.
 *
 * ── Subida RESUMABLE, no simple ────────────────────────────────────────────
 * Drive acepta subir el archivo de un tirón (`uploadType=media`) y para una
 * grabación de clase eso es una mala idea: son cientos de megas por una conexión
 * que puede cortarse, y un corte obliga a empezar de cero. La resumable pide
 * primero una URL de sesión y luego manda el contenido contra ella, que es lo
 * que Google recomienda a partir de 5 MB — y una clase de una hora nunca baja de
 * ahí.
 *
 * ── El archivo se manda por TROZOS ─────────────────────────────────────────
 * Leer el video entero a memoria para subirlo tumbaría el proceso: PHP tiene un
 * límite de memoria y un video de dos horas lo pasa. Se abre el archivo y se
 * empuja de a poco.
 */
class DestinoDrive implements Destino
{
    public function __construct(
        private readonly DestinoGrabacion $config,
        private readonly TokenDeServicio $tokens,
    ) {}

    public function subir(string $rutaLocal, string $nombre, string $carpeta): ArchivoArchivado
    {
        $credenciales = $this->config->credencialesArray();
        $token = $this->token();
        $bytes = filesize($rutaLocal);

        // 1. Pedir la sesión. Aquí van los metadatos, no el contenido.
        $sesion = Http::withToken($token)
            ->timeout(30)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', [
                'name' => $nombre,
                'parents' => [$credenciales['carpeta_id']],
                // La subcarpeta se dice en la descripción y no se crea: crear
                // carpetas exige listarlas primero para no duplicarlas, y eso es
                // una llamada más por cada grabación. La carpeta que importa —la
                // de la escuela— ya la eligió el administrador.
                'description' => "Grabación de clase · {$carpeta}",
            ]);

        $this->exigirExito($sesion, 'No se pudo iniciar la subida a Drive.');

        $destino = $sesion->header('Location');

        AvisoParaElUsuario::si(
            blank($destino),
            502,
            'Drive aceptó la petición pero no devolvió a dónde subir. Vuelve a intentarlo.',
        );

        // 2. Empujar el contenido contra la URL de sesión.
        $manejador = fopen($rutaLocal, 'rb');

        AvisoParaElUsuario::si($manejador === false, 500, 'No se pudo leer el archivo descargado.');

        $subida = Http::withToken($token)
            ->withHeaders(['Content-Length' => (string) $bytes])
            // Sin límite de tiempo corto: una grabación grande tarda minutos, y
            // cortarla a los treinta segundos haría que no se archivara nunca
            // ninguna clase larga —justo las que interesa guardar—.
            ->timeout(1800)
            ->withBody($manejador, 'application/octet-stream')
            ->put($destino);

        if (is_resource($manejador)) {
            fclose($manejador);
        }

        $this->exigirExito($subida, 'No se pudo subir la grabación a Drive.');

        $id = $subida->json('id');

        return new ArchivoArchivado(
            ruta: (string) $id,
            // El enlace de siempre de Drive. Quién lo puede abrir lo deciden los
            // permisos de la carpeta: Acadion no los toca, porque compartir una
            // clase con menores dentro no es algo que deba hacer un archivador.
            url: $id ? "https://drive.google.com/file/d/{$id}/view" : null,
            bytes: $bytes ?: null,
        );
    }

    /**
     * Token de Drive, actuando como la cuenta que se configuró.
     *
     * El alcance es `drive.file`, que sólo alcanza a los archivos que la propia
     * app crea. Es deliberado: con el alcance completo de Drive, una credencial
     * filtrada abriría todo el Drive de la escuela.
     */
    private function token(): string
    {
        $credenciales = $this->config->credencialesArray();

        return $this->tokens->para(
            (string) ($credenciales['cuenta_servicio_json'] ?? ''),
            (string) ($credenciales['como_quien'] ?? ''),
            TokenDeServicio::DRIVE_ESCRITURA,
        );
    }

    private function exigirExito($respuesta, string $mensaje): void
    {
        if ($respuesta->successful()) {
            return;
        }

        $detalle = $respuesta->json('error.message') ?? $respuesta->body();

        Log::warning('Drive respondió con error', ['estado' => $respuesta->status(), 'cuerpo' => $respuesta->body()]);

        AvisoParaElUsuario::lanzar(502, trim("{$mensaje} Google dijo: {$detalle}"));
    }
}
