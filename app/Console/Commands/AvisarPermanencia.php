<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Permanencia\AvisosDeSenales;
use App\Services\Permanencia\VigilanciaDeCasos;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lo único de este módulo que le habla a una persona.
 *
 * ── APARTE de `permanencia:evaluar`, y DESPUÉS ────────────────────────────
 * Después porque el aviso habla de lo que el motor acaba de levantar; aparte
 * porque esto NOTIFICA, y esconderlo dentro de un comando llamado «evaluar» es
 * como se llega a que nadie sepa de dónde salió un mensaje. Es exactamente el
 * argumento con el que `finanzas:recordar-cobranza` se separó de
 * `finanzas:evaluar`.
 *
 * ── A las 07:45 y no de madrugada ─────────────────────────────────────────
 * Porque la hora la ve quien lo recibe. Un aviso sobre la situación de alguien
 * fechado a las tres de la mañana se lee como si la escuela trabajara de noche,
 * y a esa hora quien lo recibe no puede hacer nada al respecto. Y la franja de
 * `/plataforma/configuracion` lo sostiene aunque alguien corra esto a mano.
 *
 * ── Se llama `avisar` y no `vigilar-sla`, que es lo que decía el plan ─────
 * Porque no escala nada: `escalado` es un estado que una persona elige y que
 * exige decir por qué. Un comando moviéndolo haría que ese estado significara
 * dos cosas —«alguien pidió ayuda» y «nadie contestó a tiempo»—, que es el error
 * que este módulo evitó separando las dos máquinas de estados. Y el nombre tiene
 * que decir lo que hace: quien lea el despachador buscando de dónde salió un
 * mensaje lo encuentra aquí.
 *
 * ── `--seco` no escribe NADA ──────────────────────────────────────────────
 * Existe para poder encenderlo con calma. Si dejara rastro, la primera prueba en
 * seco mataría el aviso de verdad — porque el rastro es justamente lo que impide
 * el segundo.
 */
class AvisarPermanencia extends Command
{
    protected $signature = 'permanencia:avisar
        {--tenant=* : Sólo estas escuelas. Sin esto, todas.}
        {--seco : Dice a quién le llegaría, sin escribir nada.}';

    protected $description = 'Avisa de las señales y vigila los plazos de los casos de seguimiento.';

    public function handle(AvisosDeSenales $senales, VigilanciaDeCasos $casos): int
    {
        $escuelas = $this->option('tenant') !== []
            ? Tenant::query()->whereIn('id', $this->option('tenant'))->get()
            : Tenant::all();

        $seco = (bool) $this->option('seco');

        $seco && $this->warn('Modo seco: no se escribe nada y no se manda ningún aviso.');

        $fallaron = [];
        $filas = [];

        foreach ($escuelas as $escuela) {
            /*
             * Cada escuela AISLADA: la que reviente no puede dejar a las demás
             * sin avisar. Es lo que ya hacen `procesos:avisar` y
             * `finanzas:generar-cargos`.
             */
            try {
                tenancy()->initialize($escuela);

                $levantados = array_merge(
                    $senales->correr(seco: $seco),
                    $casos->correr(seco: $seco),
                );

                foreach ($this->porEvento($levantados) as $evento => $cuantos) {
                    $filas[] = [$escuela->id, $evento, $cuantos];
                }

                $levantados === [] && $filas[] = [$escuela->id, '—', 0];
            } catch (Throwable $e) {
                $fallaron[] = $escuela->id.': '.$e->getMessage();
            } finally {
                tenancy()->end();
            }
        }

        $filas === [] || $this->table(['Escuela', 'Evento', 'Avisos'], $filas);

        if ($fallaron !== []) {
            $this->newLine();
            $this->error('Escuelas que fallaron:');

            foreach ($fallaron as $linea) {
                $this->line('  · '.$linea);
            }

            return self::FAILURE;
        }

        $this->info('Terminado.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $levantados
     * @return array<string, int>
     */
    private function porEvento(array $levantados): array
    {
        $conteo = [];

        foreach ($levantados as $uno) {
            $conteo[$uno['evento']] = ($conteo[$uno['evento']] ?? 0) + 1;
        }

        return $conteo;
    }
}
