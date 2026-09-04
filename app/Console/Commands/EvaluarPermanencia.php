<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Permanencia\MotorDeEvaluacion;
use Illuminate\Console\Command;
use Throwable;

/**
 * Corre el motor de alertas sobre una escuela o sobre todas.
 *
 * ── Por qué es un comando y no un botón ───────────────────────────────────
 * Lo que estas reglas miden se vuelve cierto SIN que nadie haga nada: pasa el
 * tiempo, se acumula una falta, vence un cargo. No hay ningún punto de la
 * aplicación desde el que dispararlo. Mismo argumento que
 * `finanzas:conciliar-cfdi` y `procesos:avisar`.
 *
 * ── A las 05:00, y el orden importa ───────────────────────────────────────
 * DESPUÉS de `finanzas:generar-cargos` (02:45) y `finanzas:evaluar` (03:00),
 * porque una regla de adeudo leída antes vería una cartera a medio generar y
 * levantaría alertas sobre cargos que aún no existen. Y ANTES de los avisos de
 * las 07:00–07:45, para que lo que se notifique sea de hoy.
 *
 * ── Sale con ERROR si alguna regla reventó ────────────────────────────────
 * Un comando de madrugada que termina en verde teniendo reglas rotas es cómo
 * esto se queda sin mirar durante meses. Las reglas rotas no detienen a las
 * demás —cada una va aislada— pero sí se reportan y sí cambian el código de
 * salida.
 */
class EvaluarPermanencia extends Command
{
    protected $signature = 'permanencia:evaluar
        {--tenant=* : Sólo estas escuelas. Sin esto, todas.}
        {--matricula=* : Sólo estas matrículas. Para recalcular a una persona.}
        {--seco : Mide y no escribe nada.}';

    protected $description = 'Evalúa las reglas de alerta y levanta, actualiza o cierra las señales.';

    public function handle(MotorDeEvaluacion $motor): int
    {
        $escuelas = $this->option('tenant') !== []
            ? Tenant::query()->whereIn('id', $this->option('tenant'))->get()
            : Tenant::all();

        $matriculas = array_map('intval', $this->option('matricula'));
        $seco = (bool) $this->option('seco');

        $seco && $this->warn('Modo seco: no se escribe nada.');

        $fallaron = [];
        $filas = [];

        foreach ($escuelas as $escuela) {
            /*
             * Cada escuela AISLADA: la que reviente no puede dejar sin evaluar
             * a las demás. Es lo que ya hacen `procesos:avisar` y
             * `finanzas:generar-cargos`.
             */
            try {
                tenancy()->initialize($escuela);

                $corrida = $motor->correr(
                    matriculas: $matriculas === [] ? null : $matriculas,
                    disparo: $matriculas === [] ? 'programada' : 'manual',
                    seco: $seco,
                );

                $filas[] = [
                    $escuela->id,
                    $corrida->matriculas_evaluadas,
                    $corrida->reglas_evaluadas,
                    $corrida->alertas_creadas,
                    $corrida->alertas_actualizadas,
                    $corrida->alertas_resueltas,
                    $corrida->alertas_obsoletas,
                    $corrida->sin_datos,
                    $corrida->milisegundos.' ms',
                ];

                foreach ($corrida->errores ?? [] as $error) {
                    $fallaron[] = $escuela->id.' · '.$error['regla'].': '.$error['error'];
                }
            } catch (Throwable $e) {
                $fallaron[] = $escuela->id.' · la escuela entera: '.$e->getMessage();
            } finally {
                tenancy()->end();
            }
        }

        $filas === [] || $this->table(
            ['Escuela', 'Alumnos', 'Reglas', 'Nuevas', 'Actualizadas', 'Resueltas', 'Obsoletas', 'Sin datos', 'Tardó'],
            $filas,
        );

        if ($fallaron !== []) {
            $this->newLine();
            $this->error('Reglas que fallaron (las demás sí se evaluaron):');

            foreach ($fallaron as $linea) {
                $this->line('  · '.$linea);
            }

            return self::FAILURE;
        }

        $this->info('Evaluación terminada sin errores.');

        return self::SUCCESS;
    }
}
