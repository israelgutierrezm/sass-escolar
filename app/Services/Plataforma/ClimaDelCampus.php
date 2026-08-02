<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Models\Academico\Campus;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El clima donde la persona estudia o da clase.
 *
 * ── De dónde sale la ubicación ─────────────────────────────────────────────
 * Del CAMPUS, no de la IP. Desde la red de la escuela todas las peticiones
 * salen por el mismo enlace, así que geolocalizar por IP le mostraría a media
 * escuela el clima del proveedor de internet; con VPN da cualquier cosa. Y la
 * IP de una persona es un dato personal: mandarla a un tercero para adornar una
 * tarjeta no se sostiene cuando el sistema ya sabe dónde estudia.
 *
 * La IP queda como respaldo para cuando el campus no tiene coordenadas
 * capturadas, y sólo entonces.
 *
 * ── Nunca rompe el panel ───────────────────────────────────────────────────
 * Es un adorno útil, no información crítica. Si la API tarda o falla, se
 * devuelve null y la tarjeta no aparece: el panel de la escuela no puede
 * depender de que un servicio gratuito de otro país esté de pie.
 *
 * ── Se consulta poco ───────────────────────────────────────────────────────
 * El clima no cambia en diez minutos y el panel se abre cientos de veces al
 * día. Se guarda por ubicación —no por usuario—, así que todo un campus
 * comparte una sola consulta.
 */
class ClimaDelCampus
{
    /** Media hora: el clima cambia despacio y el panel se abre mucho. */
    private const MINUTOS_CACHE = 30;

    /** Corto a propósito: nada de esto vale hacer esperar al usuario. */
    private const SEGUNDOS_ESPERA = 4;

    public function __construct(private readonly ContextoAcademico $contexto) {}

    /**
     * Dónde se guarda lo que responden las APIs.
     *
     * Un almacén PROPIO, fuera de la caché del tenant, por dos razones:
     *
     * 1. La caché de tenancy etiqueta todo para aislar por escuela, y el driver
     *    de este proyecto (`database`) no admite etiquetas: cualquier
     *    `Cache::remember` aquí revienta con «does not support tagging».
     * 2. Y aunque las admitiera, no habría por qué aislarlo: el clima de unas
     *    coordenadas no es dato de nadie. Dos escuelas de la misma ciudad
     *    comparten el mismo cielo y bien pueden compartir la consulta.
     */
    private function almacen(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::build([
            'driver' => 'file',
            'path' => storage_path('framework/cache/externos'),
        ]);
    }

    /**
     * El clima que le toca ver a este usuario, o null si no se pudo saber.
     *
     * @return array<string, mixed>|null
     */
    public function para(Usuario $usuario, ?string $ip = null): ?array
    {
        $lugar = $this->ubicacionDe($usuario, $ip);

        if ($lugar === null) {
            return null;
        }

        $llave = sprintf('clima:%.3f:%.3f', $lugar['latitud'], $lugar['longitud']);

        $datos = $this->almacen()->remember(
            $llave,
            now()->addMinutes(self::MINUTOS_CACHE),
            fn () => $this->consultar($lugar['latitud'], $lugar['longitud']),
        );

        if ($datos === null) {
            // No se cachea el fallo más allá de un intento: si la API vuelve en
            // dos minutos, no tiene sentido quedarse media hora sin clima.
            $this->almacen()->forget($llave);

            return null;
        }

        return [...$datos, 'lugar' => $lugar['nombre'], 'aproximado' => $lugar['aproximado']];
    }

    /**
     * Dónde está la persona: su campus si tiene coordenadas; si no, su IP.
     *
     * @return array{latitud: float, longitud: float, nombre: string, aproximado: bool}|null
     */
    private function ubicacionDe(Usuario $usuario, ?string $ip): ?array
    {
        $ids = $this->contexto->de($usuario->persona_id)['campus'];

        $conCoordenadas = Campus::query()
            ->whereNotNull('latitud')
            ->whereNotNull('longitud');

        // Primero el suyo: donde estudia, donde da clase o a donde lo acota su
        // rol.
        $campus = $ids === [] ? null : (clone $conCoordenadas)->whereIn('id', $ids)->first();

        /*
         * Y si no tiene ninguno —dirección general, alcance global— el plantel
         * de la escuela. Quien manda sobre todos los campus sigue estando en
         * alguna parte, y el clima de la matriz es mejor respuesta que ninguna.
         */
        $campus ??= $conCoordenadas->orderBy('id')->first();

        if ($campus !== null) {
            return [
                'latitud' => (float) $campus->latitud,
                'longitud' => (float) $campus->longitud,
                'nombre' => $campus->nombre,
                'aproximado' => false,
            ];
        }

        return $this->porIp($ip);
    }

    /**
     * El respaldo: aproximar por la IP de quien entra.
     *
     * Se marca como `aproximado` para que la tarjeta lo diga —«cerca de
     * Guadalajara»— en vez de afirmar una ubicación que puede ser la del
     * proveedor de internet. Las direcciones privadas ni se intentan: en
     * desarrollo y detrás de un proxy no dicen nada.
     *
     * @return array{latitud: float, longitud: float, nombre: string, aproximado: bool}|null
     */
    private function porIp(?string $ip): ?array
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $this->almacen()->remember("clima:ip:{$ip}", now()->addHours(6), function () use ($ip) {
            try {
                $r = Http::timeout(self::SEGUNDOS_ESPERA)
                    ->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,city,lat,lon']);

                $d = $r->json();

                if (($d['status'] ?? '') !== 'success') {
                    return null;
                }

                return [
                    'latitud' => (float) $d['lat'],
                    'longitud' => (float) $d['lon'],
                    'nombre' => (string) ($d['city'] ?? 'tu zona'),
                    'aproximado' => true,
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /**
     * Lo que responde Open-Meteo, ya masticado.
     *
     * Se pide todo en dos llamadas —clima y aire— y se devuelve listo para
     * pintar: la vista no debería saber qué es un «código WMO».
     *
     * @return array<string, mixed>|null
     */
    private function consultar(float $latitud, float $longitud): ?array
    {
        try {
            $clima = Http::timeout(self::SEGUNDOS_ESPERA)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $latitud,
                'longitude' => $longitud,
                'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,is_day,weather_code,wind_speed_10m',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                'timezone' => 'auto',
                'forecast_days' => 4,
            ]);

            if (! $clima->successful()) {
                return null;
            }

            $d = $clima->json();
            $ahora = $d['current'] ?? null;

            if ($ahora === null) {
                return null;
            }

            return [
                'temperatura' => (int) round((float) $ahora['temperature_2m']),
                'sensacion' => (int) round((float) $ahora['apparent_temperature']),
                'humedad' => (int) $ahora['relative_humidity_2m'],
                'viento' => (int) round((float) $ahora['wind_speed_10m']),
                'es_de_dia' => (bool) $ahora['is_day'],
                'condicion' => ClimaWmo::texto((int) $ahora['weather_code']),
                'icono' => ClimaWmo::icono((int) $ahora['weather_code'], (bool) $ahora['is_day']),
                'proximos' => $this->proximosDias($d['daily'] ?? []),
                'aire' => $this->consultarAire($latitud, $longitud),
                'actualizado' => now()->format('H:i'),
            ];
        } catch (\Throwable $e) {
            // Se anota pero no se propaga: que no haya clima no puede tumbar el
            // panel de nadie.
            Log::info('No se pudo consultar el clima: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Los próximos días, sin incluir hoy.
     *
     * @param  array<string, mixed>  $diario
     * @return array<int, array<string, mixed>>
     */
    private function proximosDias(array $diario): array
    {
        $dias = [];
        $fechas = $diario['time'] ?? [];

        // Desde 1: el 0 es hoy, y de hoy ya se muestra la temperatura de ahora.
        for ($i = 1; $i < min(count($fechas), 4); $i++) {
            $dias[] = [
                'fecha' => $fechas[$i],
                'dia' => $this->diaCorto($fechas[$i]),
                'maxima' => (int) round((float) $diario['temperature_2m_max'][$i]),
                'minima' => (int) round((float) $diario['temperature_2m_min'][$i]),
                'lluvia' => (int) ($diario['precipitation_probability_max'][$i] ?? 0),
                'icono' => ClimaWmo::icono((int) $diario['weather_code'][$i], true),
                'condicion' => ClimaWmo::texto((int) $diario['weather_code'][$i]),
            ];
        }

        return $dias;
    }

    /**
     * La calidad del aire, si se puede.
     *
     * Va en su propia llamada —es otro servicio de Open-Meteo— y su fallo no
     * arrastra al clima: se prefiere una tarjeta con temperatura y sin aire a
     * ninguna tarjeta.
     *
     * @return array<string, mixed>|null
     */
    private function consultarAire(float $latitud, float $longitud): ?array
    {
        try {
            $r = Http::timeout(self::SEGUNDOS_ESPERA)->get('https://air-quality-api.open-meteo.com/v1/air-quality', [
                'latitude' => $latitud,
                'longitude' => $longitud,
                'current' => 'us_aqi',
                'timezone' => 'auto',
            ]);

            $indice = $r->json('current.us_aqi');

            if ($indice === null) {
                return null;
            }

            return ['indice' => (int) $indice, ...ClimaWmo::aire((int) $indice)];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function diaCorto(string $fecha): string
    {
        $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

        return $dias[(int) date('w', strtotime($fecha))] ?? '';
    }
}
