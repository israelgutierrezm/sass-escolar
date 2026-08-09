<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\UrlPublica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja lista una escuela para recibir avisos de pasarela por un túnel.
 *
 * ── El problema que resuelve ───────────────────────────────────────────────
 * Levantar ngrok no basta, y es la parte que sorprende. Las escuelas se
 * identifican POR DOMINIO: `demo.localhost` está registrado y por eso resuelve.
 * Un túnel entrega otro host —`abc123.ngrok-free.app`—, que no está registrado
 * en ninguna parte, así que el aviso de la pasarela llega, no encuentra escuela
 * y muere en un 404.
 *
 * Y lo peor es cómo se ve desde fuera: el cobro se abre bien, la liga de pago
 * funciona, quien paga paga… y el pago no se aplica nunca. Nada falla a la
 * vista. Este comando registra el host del túnel como dominio de la escuela,
 * que es lo único que falta.
 *
 * El host sale de `PAGOS_URL_PUBLICA`, la misma que se le manda a la pasarela:
 * así no hay dos sitios donde escribir la dirección del túnel y desincronizarse.
 */
class TunelDePagos extends Command
{
    protected $signature = 'pagos:tunel
        {escuela : La escuela (tenant) que va a recibir los avisos}
        {--quitar : Desregistra el dominio del túnel en vez de registrarlo}';

    protected $description = 'Registra el dominio del túnel para que los avisos de pasarela encuentren la escuela';

    public function handle(): int
    {
        $tenant = Tenant::find($this->argument('escuela'));

        if ($tenant === null) {
            $this->error('No existe la escuela «'.$this->argument('escuela').'».');

            return self::FAILURE;
        }

        $host = UrlPublica::host();

        if ($host === null) {
            $this->error('Falta PAGOS_URL_PUBLICA en el .env.');
            $this->newLine();
            $this->line('Levanta el túnel y pega la dirección que te dé:');
            $this->line('  PAGOS_URL_PUBLICA=https://abc123.ngrok-free.app');
            $this->newLine();
            $this->line('Después vuelve a correr este comando.');

            return self::FAILURE;
        }

        return $this->option('quitar')
            ? $this->quitar($tenant, $host)
            : $this->registrar($tenant, $host);
    }

    private function registrar(Tenant $tenant, string $host): int
    {
        $deOtra = DB::connection('central')->table('domains')
            ->where('domain', $host)
            ->where('tenant_id', '!=', $tenant->getTenantKey())
            ->value('tenant_id');

        if ($deOtra !== null) {
            $this->error("El dominio {$host} ya está registrado para la escuela «{$deOtra}».");
            $this->line('Quítalo de ahí primero: php artisan pagos:tunel '.$deOtra.' --quitar');

            return self::FAILURE;
        }

        $yaEstaba = DB::connection('central')->table('domains')
            ->where('domain', $host)
            ->where('tenant_id', $tenant->getTenantKey())
            ->exists();

        if (! $yaEstaba) {
            DB::connection('central')->table('domains')->insert([
                'domain' => $host,
                'tenant_id' => $tenant->getTenantKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info($yaEstaba
            ? "El dominio {$host} ya apuntaba a «{$tenant->getTenantKey()}»."
            : "Listo: {$host} ahora resuelve a la escuela «{$tenant->getTenantKey()}».");

        $this->newLine();
        $this->line('Los avisos de pasarela se mandarán a:');
        $this->line('  '.UrlPublica::base().'/pagos/aviso/{pasarela}');
        $this->newLine();

        /*
         * El recordatorio del túnel efímero. Un ngrok gratuito cambia de
         * dirección en cada arranque, y el dominio registrado se queda apuntando
         * al anterior: los avisos vuelven a morir en un 404 sin que nada avise.
         */
        $this->comment('Cada vez que reinicies el túnel cambia la dirección:');
        $this->comment('actualiza PAGOS_URL_PUBLICA y vuelve a correr esto.');
        $this->newLine();
        $this->line('Al terminar, para no dejar un dominio muerto en la base:');
        $this->line('  php artisan pagos:tunel '.$tenant->getTenantKey().' --quitar');

        return self::SUCCESS;
    }

    private function quitar(Tenant $tenant, string $host): int
    {
        $borrados = DB::connection('central')->table('domains')
            ->where('domain', $host)
            ->where('tenant_id', $tenant->getTenantKey())
            ->delete();

        $this->info($borrados > 0
            ? "Quitado: {$host} ya no resuelve a «{$tenant->getTenantKey()}»."
            : "No había nada que quitar: {$host} no estaba registrado para «{$tenant->getTenantKey()}».");

        return self::SUCCESS;
    }
}
