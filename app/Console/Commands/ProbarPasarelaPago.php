<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Finanzas\PasarelaPago;
use App\Models\Tenant;
use App\Services\Pagos\Pasarelas;
use App\Support\PasarelasCatalogo;
use Illuminate\Console\Command;
use Throwable;
use App\Support\UrlPublica;

/**
 * Comprueba que una pasarela puede cobrar de verdad, ANTES de que lo descubra
 * un alumno.
 *
 * ── Por qué hace falta ─────────────────────────────────────────────────────
 * La pantalla de configuración sólo sabe si los campos están llenos, no si
 * SIRVEN. Un token de pruebas pegado en producción, una llave revocada o un
 * espacio de más al copiarla dan una pasarela que se ve encendida y perfecta
 * hasta que alguien intenta pagar y se encuentra un error en pantalla.
 *
 * Esto abre un cobro real contra la API —de un peso, y sin que nadie lo pague—
 * para que la respuesta la dé la pasarela y no una suposición nuestra.
 *
 * ── No cobra nada ──────────────────────────────────────────────────────────
 * Crear una orden no mueve dinero: sólo existe hasta que alguien la paga. La
 * intención que se crea aquí queda marcada como cancelada al terminar, así que
 * tampoco ensucia la caja ni aparece en el estado de cuenta de nadie.
 */
class ProbarPasarelaPago extends Command
{
    protected $signature = 'pagos:probar
        {pasarela : mercadopago, conekta, stripe, openpay o paypal}
        {--tenant= : La escuela cuyas credenciales se prueban}';

    protected $description = 'Abre un cobro de prueba contra la pasarela para comprobar sus credenciales';

    public function handle(): int
    {
        $tenant = Tenant::find($this->option('tenant'));

        if ($tenant === null) {
            $this->error('Hay que decir qué escuela con --tenant.');

            return self::FAILURE;
        }

        $clave = (string) $this->argument('pasarela');

        if (! PasarelasCatalogo::existe($clave)) {
            $this->error("No existe la pasarela «{$clave}».");

            return self::FAILURE;
        }

        return $tenant->run(fn () => $this->probar($clave));
    }

    private function probar(string $clave): int
    {
        if (config('pagos.modo') === 'fake') {
            $this->warn('PAGOS_MODO=fake: esto hablaría con la pasarela de mentira, no con la de verdad.');
            $this->line('Pon PAGOS_MODO=real para que la prueba sirva de algo.');

            return self::FAILURE;
        }

        $config = PasarelaPago::para($clave);
        $nombre = PasarelasCatalogo::nombreDe($clave);

        $this->line("Probando {$nombre} en ambiente <options=bold>{$config->ambiente}</>…");
        $this->newLine();

        try {
            $pasarela = app(Pasarelas::class)->para($config);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        /*
         * Se necesita una intención real porque es lo que la pasarela recibe
         * —el monto, la referencia—, pero de un peso: si algo saliera mal y
         * alguien la pagara, el daño es un peso y no una colegiatura.
         */
        $intencion = $this->intencionDePrueba($clave, $config->ambiente);

        if ($intencion === null) {
            $this->error('No hay ninguna matrícula en esta escuela con la que armar el cobro de prueba.');

            return self::FAILURE;
        }

        try {
            $iniciado = $pasarela->iniciar(
                $intencion,
                route('tenant.pagos.retorno'),
                UrlPublica::paraAfuera(route('tenant.pagos.aviso', ['pasarela' => $clave])),
            );

            $this->info("✓ {$nombre} aceptó el cobro.");
            $this->line('  Referencia: '.$iniciado->referenciaExterna);
            $this->line('  Liga de pago: '.$iniciado->url);
            $this->newLine();
            $this->line('Las credenciales sirven. El aviso se pidio a:');
            $this->line('  '.UrlPublica::paraAfuera(route('tenant.pagos.aviso', ['pasarela' => $clave])));
            $this->newLine();

            /*
             * El aviso a una direccion que no existe fuera es el fallo mudo
             * de este modulo: el cobro se abre, la liga funciona, alguien
             * paga, y el pago no se aplica nunca. Se avisa aqui, que es el
             * unico momento en que alguien esta mirando.
             */
            if (UrlPublica::base() === null) {
                $this->warn('Esa direccion tiene que ser alcanzable desde internet.');
                $this->line('Si estas en local, levanta un tunel y configura:');
                $this->line('  PAGOS_URL_PUBLICA=https://loquetedeeltunel');
                $this->line('  php artisan pagos:tunel '.tenant()->getTenantKey());
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('✗ '.$e->getMessage());
            $this->newLine();
            $this->line('Repasa las credenciales del ambiente '.$config->ambiente.' en');
            $this->line('Plataforma → Pasarelas de pago.');

            return self::FAILURE;
        } finally {
            // Un intento de diagnóstico no es un cobro pendiente de nadie.
            $intencion->update([
                'estado' => \App\Models\Finanzas\IntencionCobro::CANCELADA,
                'resuelta_en' => now(),
            ]);
        }
    }

    private function intencionDePrueba(string $clave, string $ambiente): ?\App\Models\Finanzas\IntencionCobro
    {
        $matricula = \App\Models\Admisiones\MatriculaOferta::query()->value('id');

        if ($matricula === null) {
            return null;
        }

        return \App\Models\Finanzas\IntencionCobro::create([
            'matricula_oferta_id' => $matricula,
            'pasarela' => $clave,
            'ambiente' => $ambiente,
            'monto' => 1,
            'adeudo_ids' => [],
        ]);
    }
}
