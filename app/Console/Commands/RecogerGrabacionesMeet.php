<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Models\Tenant;
use App\Services\Grabaciones\ConsultorDeGrabacionesMeet;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recoge las grabaciones de las clases dadas por Google Meet.
 *
 * ── Meet no avisa: hay que preguntarle ─────────────────────────────────────
 * Zoom manda un webhook cuando la grabación está lista. Google no tiene nada
 * equivalente para Meet, así que esto corre cada tanto y pregunta por las clases
 * que ya terminaron y todavía no tienen grabación anotada.
 *
 * Se busca en las últimas 48 horas y no en todo el historial: una grabación de
 * Meet tarda minutos u horas en aparecer, pero si a los dos días no está, no va
 * a estar — y repreguntar por todo el semestre en cada corrida es cargarle a
 * Google una consulta por cada clase que se dio nunca.
 *
 * ── Y la grabación de Meet YA ESTÁ en Drive ────────────────────────────────
 * Es la diferencia que cambia el trabajo. Google la deja en el Drive de quien
 * organizó el evento, así que aquí no siempre hay que COPIAR nada: si el destino
 * de la escuela es Drive, basta con anotar dónde está. Copiarla sería pagar dos
 * veces el mismo archivo en el mismo Drive.
 *
 * Con Dropbox o con el disco propio sí se copia, porque el archivo tiene que
 * salir de Google.
 *
 * ── Lo que exige, y lo que no se ha podido comprobar ───────────────────────
 * La escuela necesita Workspace con grabación habilitada y la cuenta de servicio
 * con el alcance `meetings.space.readonly` (más `drive.readonly` para bajar el
 * archivo, salvo que el destino sea el propio Drive).
 *
 * El viaje de ida y vuelta contra Google NO está comprobado: está escrito contra
 * la forma documentada de la API v2 y probado con respuestas fingidas de esa
 * forma. Para ejercitarlo de verdad hace falta un Workspace. Lo que sí se
 * procuró es que cada respuesta inesperada quede en el registro con su cuerpo,
 * en vez de devolver una lista vacía —que es indistinguible de «no se grabó» y
 * dejaría a la escuela creyendo que todo va bien—.
 */
class RecogerGrabacionesMeet extends Command
{
    protected $signature = 'clases:recoger-grabaciones
        {--tenant= : Una escuela; sin esto, todas}
        {--horas=48 : Hasta cuántas horas atrás mirar}';

    protected $description = 'Busca en Google las grabaciones de las clases de Meet ya terminadas';

    public function handle(): int
    {
        $escuelas = $this->option('tenant') !== null
            ? Tenant::query()->whereKey($this->option('tenant'))->get()
            : Tenant::all();

        foreach ($escuelas as $escuela) {
            /*
             * Cada escuela por separado y aislada: una con las credenciales mal
             * no puede dejar sin revisar a las demás. Es la misma decisión que
             * en la generación de cargos, y por el mismo motivo.
             */
            try {
                $escuela->run(fn () => $this->revisar($escuela->getTenantKey()));
            } catch (\Throwable $e) {
                $this->error("  {$escuela->getTenantKey()}: {$e->getMessage()}");

                Log::warning('Falló la recolección de grabaciones', [
                    'escuela' => $escuela->getTenantKey(),
                    'motivo' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function revisar(string $escuela): void
    {
        $integracion = IntegracionVideo::para(ProveedoresVideoCatalogo::MEET);

        if (! $integracion->operativa()) {
            return;
        }

        $candidatas = Videoconferencia::query()
            ->where('proveedor', ProveedoresVideoCatalogo::MEET)
            ->where('estado', '!=', Videoconferencia::CANCELADA)
            ->where('fin', '<', now())
            ->where('fin', '>', now()->subHours((int) $this->option('horas')))
            // Las que ya tienen algo anotado no se vuelven a preguntar.
            ->whereDoesntHave('grabaciones')
            ->get();

        if ($candidatas->isEmpty()) {
            return;
        }

        $this->line("  {$escuela}: {$candidatas->count()} clase(s) de Meet por revisar");

        $consultor = app(ConsultorDeGrabacionesMeet::class);
        $encontradas = 0;

        foreach ($candidatas as $clase) {
            /*
             * Clase por clase, aislada. Una con el enlace ilegible o con la
             * cuenta dada de baja no puede dejar sin revisar a las demás —que es
             * lo que pasaría con una sola excepción al aire—.
             */
            try {
                $encontradas += $consultor->revisar($clase);
            } catch (\Throwable $e) {
                $this->warn("    clase {$clase->id}: {$e->getMessage()}");

                Log::warning('No se pudo revisar una clase de Meet', [
                    'clase' => $clase->id,
                    'motivo' => $e->getMessage(),
                ]);
            }
        }

        $this->line($encontradas > 0
            ? "    {$encontradas} grabación(es) nueva(s)"
            : '    sin grabaciones nuevas');
    }
}
