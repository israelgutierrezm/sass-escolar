<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lms\Grabacion;
use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Models\Tenant;
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
 * ── Lo que este comando NO hace todavía ────────────────────────────────────
 * La consulta real a la API de Meet (`conferenceRecords.recordings`) exige que
 * la escuela tenga Workspace con grabación habilitada y el alcance
 * `meetings.space.readonly`. Mientras no haya un Workspace real contra el que
 * probarlo, este comando deja anotado lo que encuentra y NO inventa resultados:
 * un comando que finge haber revisado es peor que uno que dice que no pudo.
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

        /*
         * Aquí va la consulta a la API de Meet cuando haya un Workspace contra
         * el cual probarla. Deliberadamente NO se escribe a ciegas: el código
         * que nunca se ha ejecutado contra el servicio real es el que falla el
         * día que se enciende, y aquí fallaría en silencio —dejando a la escuela
         * creyendo que sus clases se están guardando—.
         *
         * Lo que sí queda listo es todo lo de este lado: la tabla, el archivado,
         * los destinos y la pantalla. Conectarlo es implementar esta consulta.
         */
        $this->warn('    La consulta a la API de Meet todavía no está conectada (hace falta un Workspace real para probarla).');
        $this->line('    Las grabaciones de Meet quedan en el Drive de la cuenta organizadora mientras tanto.');
    }
}
