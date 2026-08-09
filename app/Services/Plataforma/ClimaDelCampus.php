<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Models\Academico\Campus;
use App\Models\Identidad\Usuario;
use App\Support\CacheExterno;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El clima de donde está quien mira el panel.
 *
 * ── De dónde sale la ubicación ─────────────────────────────────────────────
 * De la IP primero; el campus queda de respaldo. Antes era al revés, y el
 * motivo del cambio es práctico: capturar la latitud y la longitud de cada
 * plantel es un paso que nadie da, así que en la práctica el clima no aparecía
 * en ninguna parte. Una ubicación aproximada que se ve gana a una exacta que
 * nunca se configura.
 *
 * Lo que se pierde al hacerlo así, y conviene tener presente:
 *
 * - Desde la red de la escuela todas las peticiones salen por el mismo enlace,
 *   así que media escuela verá la ubicación del proveedor de internet. Con VPN,
 *   cualquier cosa. Por eso lo que viene por IP se marca `aproximado` y la
 *   tarjeta lo dice —«cerca de Guadalajara»— en vez de afirmar un lugar.
 * - La IP de una persona es un dato personal y viaja a un tercero
 *   (`ip-api.com`) para adornar una tarjeta. El servicio gratuito sólo atiende
 *   por HTTP, así que además va sin cifrar. Si algún día eso pesa más que la
 *   comodidad, basta con volver a poner el campus primero: es el orden de dos
 *   líneas en `ubicacionDe`.
 *
 * El campus sigue sirviendo: es lo que se usa cuando la IP no dice nada
 * —una dirección privada en desarrollo, detrás de un proxy, o el servicio
 * caído—, y entonces la ubicación es exacta.
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

    // El almacén y la regla de «el fallo no se guarda» viven en
    // {@see CacheExterno}: lo mismo hace falta aquí, en los feriados y en el
    // tipo de cambio, y tres copias es donde una se queda sin el `forget`.

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

        // Por ubicación redondeada a tres decimales —unos cien metros—, no por
        // usuario: todo un campus comparte una sola consulta.
        $datos = CacheExterno::recordar(
            sprintf('clima:%.3f:%.3f', $lugar['latitud'], $lugar['longitud']),
            self::MINUTOS_CACHE,
            fn () => $this->consultar($lugar['latitud'], $lugar['longitud']),
        );

        if ($datos === null) {
            return null;
        }

        return [...$datos, 'lugar' => $lugar['nombre'], 'aproximado' => $lugar['aproximado']];
    }

    /**
     * Dónde está la persona: su IP si dice algo; si no, su campus.
     *
     * @return array{latitud: float, longitud: float, nombre: string, aproximado: bool}|null
     */
    private function ubicacionDe(Usuario $usuario, ?string $ip): ?array
    {
        // La IP manda. Si no resuelve —dirección privada, servicio caído— se
        // cae al campus, que además da una ubicación exacta.
        $porIp = $this->porIp($ip);

        if ($porIp !== null) {
            return $porIp;
        }

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

        return null;
    }

    /**
     * La ubicación por la IP de quien entra.
     *
     * Se marca como `aproximado` para que la tarjeta lo diga —«cerca de
     * Guadalajara»— en vez de afirmar una ubicación que puede ser la del
     * proveedor de internet, que es lo que suele ser desde la red de una
     * escuela. Las direcciones privadas ni se intentan: en desarrollo y detrás
     * de un proxy no dicen nada, y ahí manda el campus.
     *
     * @return array{latitud: float, longitud: float, nombre: string, aproximado: bool}|null
     */
    private function porIp(?string $ip): ?array
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        // Seis horas: la ubicación de una IP no se mueve como el clima. Pero si
        // la consulta falla tampoco se guarda ese fallo —de eso se encarga
        // `recordar`—, porque media jornada sin tarjeta por un parpadeo de red
        // es exactamente lo que pasaba antes.
        return CacheExterno::recordar("clima:ip:{$ip}", 6 * 60, function () use ($ip) {
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
