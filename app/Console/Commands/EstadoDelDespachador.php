<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Permanencia\EstadoDePermanencia;
use App\Services\Plataforma\EstadoDeLaCola;
use App\Services\Plataforma\LatidoDelDespachador;
use Illuminate\Console\Command;
use Throwable;

/**
 * ¿Está vivo el despachador de tareas programadas?
 *
 * ── Por qué hace falta un comando para esto ────────────────────────────────
 * Un scheduler que deja de correr NO FALLA. No hay excepción, no hay log, no
 * hay alerta: simplemente no pasa nada. El síntoma llega semanas después y por
 * otro lado —«no se generaron los recargos de marzo»— y para entonces nadie
 * relaciona una cosa con la otra.
 *
 * El latido se escribe cada minuto desde `routes/console.php`. Esto lo lee y
 * dice desde cuándo no hay señales. Devuelve código de salida 1 cuando está
 * caído, para poder engancharlo a la vigilancia del servidor sin leer su texto.
 *
 * ── Y la COLA, que falla igual de callada ─────────────────────────────────
 * Se informa aquí y no en un comando aparte porque es el mismo síntoma y el
 * mismo sitio donde alguien va a mirar: nada pasa, nadie se entera. Una cola sin
 * trabajador deja la factura sin timbrar y quien la emitió cree que sí.
 *
 * El trabajador lo levanta el propio despachador (ver `routes/console.php`), así
 * que si el despachador está caído la cola también lo está — y este comando lo
 * dice todo junto.
 */
class EstadoDelDespachador extends Command
{
    protected $signature = 'scheduler:estado {--minutos=10 : A partir de cuántos minutos sin latido se considera caído.}';

    protected $description = 'Dice si el despachador y la cola de trabajos siguen corriendo.';

    public function handle(LatidoDelDespachador $latidos, EstadoDeLaCola $cola): int
    {
        $estado = $latidos->estado(max(2, (int) $this->option('minutos')));

        if ($estado['nunca']) {
            $this->error('El despachador NUNCA ha corrido en este servidor.');
            $this->line('Instálalo con deploy/scheduler/ — ver docs/scheduler.md.');

            return self::FAILURE;
        }

        $hace = $estado['hace_minutos'];
        $cuando = $estado['ultimo'];

        if (! $estado['vivo']) {
            $this->error("El despachador lleva {$hace} minutos sin dar señales (último: {$cuando}).");
            $this->line('Las tareas programadas NO se están ejecutando, y la cola tampoco.');

            return self::FAILURE;
        }

        $this->info("El despachador está vivo. Último latido: {$cuando} (hace {$hace} min).");

        /*
         * Los DOS se reportan siempre, y el código de salida es el peor de los
         * dos. Devolviendo el de la cola en cuanto falla, una escuela con reglas
         * de permanencia rotas no aparecería en el informe hasta que alguien
         * arreglara la cola — y esto se lee justamente cuando algo va mal.
         */
        $deLaCola = $this->reportarLaCola($cola);
        $dePermanencia = $this->reportarPermanencia();

        return $deLaCola === self::SUCCESS ? $dePermanencia : $deLaCola;
    }

    /**
     * El módulo de permanencia, que falla tan callado como la cola.
     *
     * ── Por qué está aquí y no en un comando propio ────────────────────────
     * Porque se lee en el mismo sitio: quien vigila el servidor mira UNA cosa. Y
     * porque las tres formas en que este módulo se apaga —el motor deja de
     * correr, una regla revienta, los avisos no salen— no producen ningún error
     * a la vista: la bandeja simplemente se queda vacía, y una cola vacía se lee
     * como ausencia de riesgo.
     *
     * ── Recorre las ESCUELAS, porque sus tablas son del tenant ─────────────
     * Y cada una aislada: la que reviente no puede dejar sin revisar a las
     * demás.
     */
    private function reportarPermanencia(): int
    {
        $hayFalla = false;

        foreach (Tenant::all() as $escuela) {
            try {
                tenancy()->initialize($escuela);

                $estado = app(EstadoDePermanencia::class)->estado();

                if (($estado['aplica'] ?? false) === false) {
                    continue;
                }

                $hayFalla = $this->reportarUnaEscuela($escuela->id, $estado) || $hayFalla;
            } catch (Throwable $e) {
                $this->error('Permanencia · '.$escuela->id.': '.$e->getMessage());
                $hayFalla = true;
            } finally {
                tenancy()->end();
            }
        }

        return $hayFalla ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $estado
     * @return bool si esta escuela debe hacer fallar al comando
     */
    private function reportarUnaEscuela(string $id, array $estado): bool
    {
        $falla = app(EstadoDePermanencia::class)->hayFalla($estado);

        if ($estado['nunca_corrio']) {
            $estado['reglas_activas'] > 0
                ? $this->error("Permanencia · {$id}: el motor NUNCA ha evaluado, y hay "
                    ."{$estado['reglas_activas']} regla(s) encendida(s).")
                : $this->line("Permanencia · {$id}: sin reglas encendidas todavía; no hay nada que evaluar.");
        } elseif ($falla) {
            $this->error("Permanencia · {$id}: el motor lleva {$estado['hace_dias']} día(s) sin evaluar "
                ."(último: {$estado['ultima_corrida']}).");
        } else {
            $this->info("Permanencia · {$id}: evaluado el {$estado['ultima_corrida']}, "
                ."{$estado['reglas_activas']} regla(s) encendida(s).");
        }

        /*
         * Las reglas rotas se dicen SIEMPRE y por su nombre. Es la falla que de
         * verdad se esconde: las demás reglas siguen evaluando, nada revienta a
         * la vista, y esa señal deja de levantarse para siempre.
         */
        foreach ($estado['reglas_rotas'] as $rota) {
            $this->error("  · regla rota: {$rota}");
        }

        /*
         * Y lo OPERATIVO se informa sin hacer fallar: un caso con el plazo
         * vencido es un asunto de la escuela y sale en su bandeja. Hacer que la
         * vigilancia del servidor se ponga en rojo por eso enseñaría a ignorar
         * la alarma, que es exactamente cómo se pierde.
         */
        if ($estado['sla_vencido'] > 0 || $estado['sin_asignar'] > 0) {
            $this->line("  · {$estado['sla_vencido']} caso(s) fuera de plazo de primer contacto y "
                ."{$estado['sin_asignar']} sin responsable.");
        }

        $estado['ultimo_aviso'] === null
            || $this->line("  · último aviso emitido: {$estado['ultimo_aviso']}.");

        return $falla;
    }

    /**
     * La cola, que falla tan callada como el despachador.
     *
     * Sale con error si está atorada, para que la vigilancia del servidor lo
     * note sin leer el texto — igual que con el latido.
     */
    private function reportarLaCola(EstadoDeLaCola $cola): int
    {
        $estado = $cola->estado();

        if (! $estado['hay_tabla']) {
            $this->line('La cola no usa base de datos en este servidor; no hay nada que revisar aquí.');

            return self::SUCCESS;
        }

        if ($estado['fallidos'] > 0) {
            /*
             * Los fallidos se DICEN aunque la cola esté sana: son trabajos que
             * nadie va a reintentar solo, y ahí siguen. Un timbrado fallido es
             * una factura que no existe ante el SAT.
             */
            $this->warn("Hay {$estado['fallidos']} trabajo(s) FALLIDO(s) sin reintentar.");
            $this->line('  Míralos con `php artisan queue:failed` y reintenta con `queue:retry all`.');
        }

        if ($estado['atorada']) {
            $this->error(
                "La cola está ATORADA: {$estado['pendientes']} trabajo(s) esperando, "
                ."el más viejo desde hace {$estado['espera_minutos']} minutos ({$estado['mas_viejo']})."
            );
            $this->line('  Nada se está procesando: ni timbrados ni grabaciones.');

            return self::FAILURE;
        }

        /*
         * Lo que se está haciendo AHORA se dice aparte de lo que espera. Un
         * archivado de media hora tiene la cola trabajando, no parada, y quien
         * mira esto tiene que poder distinguirlo de un trabajador muerto.
         */
        $enCurso = [];

        if ($estado['en_proceso'] > 0) {
            $enCurso[] = "{$estado['en_proceso']} en proceso";
        }

        if ($estado['diferidos'] > 0) {
            $enCurso[] = "{$estado['diferidos']} aguardando su reintento";
        }

        $cola = $enCurso === [] ? '' : ' ('.implode(', ', $enCurso).')';

        $this->info($estado['pendientes'] === 0
            ? 'La cola está al día: no hay trabajos esperando.'.$cola
            : "La cola avanza: {$estado['pendientes']} trabajo(s) esperando, el más viejo desde hace {$estado['espera_minutos']} min.".$cola);

        return self::SUCCESS;
    }
}
