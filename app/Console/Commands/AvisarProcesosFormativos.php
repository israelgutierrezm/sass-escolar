<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ProcesosFormativos\AlertasFormativas;
use Illuminate\Console\Command;
use Throwable;

/**
 * Los avisos del servicio social y las prácticas: informes, plazos y liberación.
 *
 * ── Por qué es un COMANDO y no un botón ───────────────────────────────────
 * Los cuatro casos que caza aparecen SIN un acto de nadie: pasa el tiempo. Un
 * informe se vence porque llegó su fecha, no porque alguien pulsara algo, así
 * que no hay ningún punto de la aplicación desde el que dispararlo. Mismo
 * argumento que `finanzas:conciliar-cfdi`.
 *
 * ── A las 7:00 y no de madrugada ──────────────────────────────────────────
 * Porque esto le HABLA A LA GENTE, y la hora la ve quien lo recibe. Es la misma
 * decisión que la escalera de cobranza.
 *
 * ── Y NO escribe en los expedientes ───────────────────────────────────────
 * Avisar de que un plazo venció no lo suspende: eso es una decisión con permiso
 * y bitácora, y tomarla desde un comando movería expedientes sin que nadie lo
 * pidiera. Se reporta; resolverlo es un acto deliberado.
 *
 * ── El modo SECO existe para poder encenderlo con calma ───────────────────
 * Antes de dejarlo correr, la escuela quiere ver a cuánta gente le va a llegar
 * y con qué texto. Sin eso, la única forma de saberlo es mandarlo.
 */
class AvisarProcesosFormativos extends Command
{
    protected $signature = 'procesos:avisar
                            {--tenant=* : Limitar a estas escuelas (por id). Sin esto, todas.}
                            {--seco : Sólo dice a quién le llegaría, sin levantar ningún aviso.}';

    protected $description = 'Avisa de los informes por vencer, los plazos que se acaban y los expedientes listos para liberar.';

    public function handle(AlertasFormativas $alertas): int
    {
        $ids = $this->option('tenant');
        $seco = (bool) $this->option('seco');

        $escuelas = $ids === [] ? Tenant::all() : Tenant::whereIn('id', $ids)->get();

        if ($escuelas->isEmpty()) {
            $this->warn('No hay escuelas a las que avisar.');

            return self::SUCCESS;
        }

        $seco && $this->comment('Modo seco: no se levanta ningún aviso.');

        $filas = [];
        $fallidas = [];

        foreach ($escuelas as $escuela) {
            /*
             * Una escuela que reviente NO cancela a las demás, y se REPORTA.
             *
             * Sin aislarla, la primera con datos rotos deja al resto sin sus
             * avisos y el comando termina diciendo «ok». Es la lección de
             * `finanzas:generar-cargos`, donde dos planes huérfanos del demo
             * dejaban a la escuela entera sin emitir.
             */
            try {
                $escuela->run(function () use ($escuela, $alertas, $seco, &$filas) {
                    foreach ($alertas->correr(null, $seco) as $alerta) {
                        $filas[] = [
                            $escuela->id,
                            $alerta['matricula'] ?? '—',
                            mb_substr((string) ($alerta['alumno'] ?? '—'), 0, 28),
                            $alerta['evento'],
                            mb_substr($alerta['titulo'], 0, 44),
                        ];
                    }
                });
            } catch (Throwable $falla) {
                $fallidas[] = $escuela->id.': '.$falla->getMessage();
            }
        }

        $filas === []
            ? $this->info('Nada que avisar hoy.')
            : $this->table(['Escuela', 'Matrícula', 'Alumno', 'Evento', 'Aviso'], $filas);

        $this->line(($seco ? 'Se levantarían ' : 'Avisos levantados: ').count($filas));

        if ($fallidas === []) {
            return self::SUCCESS;
        }

        /*
         * Sale con ERROR si alguna escuela falló. Uno programado que termina en
         * verde teniendo escuelas sin avisar es cómo esto se queda sin mirar
         * durante meses.
         */
        $this->newLine();
        $this->error('Estas escuelas no se pudieron procesar:');

        foreach ($fallidas as $fallida) {
            $this->line('  '.$fallida);
        }

        return self::FAILURE;
    }
}
