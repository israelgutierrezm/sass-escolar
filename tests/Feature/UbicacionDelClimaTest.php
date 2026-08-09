<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\Campus;
use App\Http\Controllers\Plataforma\ClimaController;
use App\Services\Plataforma\ClimaDelCampus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * De dónde sale la ubicación del clima.
 *
 * ── Por qué se prueba el ORDEN y no el clima ───────────────────────────────
 * Que la temperatura sea correcta es cosa de Open-Meteo. Lo que este código
 * decide es de qué punto del mapa preguntar, y ahí es donde se equivoca en
 * silencio: si la prioridad se invierte por accidente, el panel sigue
 * enseñando un clima —el de otro sitio— y nadie lo nota.
 *
 * También se prueba que NUNCA rompa: el clima es un adorno, y un servicio
 * gratuito de otro país no puede tumbar el panel de una escuela.
 */
class UbicacionDelClimaTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Sin cache entre casos: si no, el primero decide la ubicación de todos.
         *
         * Y hay que vaciar EL DE `CacheExterno`, que es un almacén de archivo
         * aparte —no el de la aplicación—: con `Cache::flush()` a secas la
         * ubicación del primer caso sobrevivía a los demás, y encima entre
         * ejecuciones, porque queda en disco.
         */
        Cache::build([
            'driver' => 'file',
            'path' => storage_path('framework/cache/externos'),
        ])->flush();
    }

    /** La IP manda: aunque el campus tenga coordenadas, se usa la de la IP. */
    public function test_la_ip_gana_al_campus(): void
    {
        $this->campusEn(19.4326, -99.1332, 'Campus Centro');
        $this->respuestas(ip: ['status' => 'success', 'city' => 'Guadalajara', 'lat' => 20.6597, 'lon' => -103.3496]);

        $clima = $this->clima('189.203.1.1');

        $this->assertSame('Guadalajara', $clima['lugar']);
        $this->assertTrue($clima['aproximado'], 'Lo que viene de una IP se marca como aproximado.');
    }

    /**
     * Si la IP no dice nada, manda el campus.
     *
     * Es el caso de todos los días en desarrollo —la IP es privada— y el de
     * cualquier instalación detrás de un proxy mal configurado.
     */
    public function test_sin_ip_util_se_cae_al_campus(): void
    {
        $this->campusEn(19.4326, -99.1332, 'Campus Centro');
        $this->respuestas();

        $clima = $this->clima('127.0.0.1');

        $this->assertSame('Campus Centro', $clima['lugar']);
        $this->assertFalse($clima['aproximado'], 'El campus da una ubicación exacta.');
    }

    /** Una IP privada ni se le pregunta al servicio: no diría nada. */
    public function test_a_una_ip_privada_no_se_le_pregunta(): void
    {
        $this->campusEn(19.4326, -99.1332, 'Campus Centro');
        $this->respuestas();

        $this->clima('192.168.1.50');

        Http::assertNotSent(fn ($p) => str_contains($p->url(), 'ip-api.com'));
    }

    /** Si el servicio de IP falla, el campus lo salva. */
    public function test_si_el_servicio_de_ip_falla_queda_el_campus(): void
    {
        $this->campusEn(19.4326, -99.1332, 'Campus Centro');
        $this->respuestas(ip: ['status' => 'fail']);

        $this->assertSame('Campus Centro', $this->clima('189.203.1.1')['lugar']);
    }

    /**
     * Sin IP útil y sin campus con coordenadas, no hay clima. Y no pasa nada.
     *
     * La tarjeta simplemente no se dibuja: es lo que impide que un servicio de
     * fuera tumbe el panel.
     */
    public function test_sin_ubicacion_no_hay_clima_y_no_revienta(): void
    {
        $this->alumnoInscrito(); // campus sin coordenadas
        $this->respuestas();

        $this->assertNull($this->climaCrudo('127.0.0.1'));
    }

    /** Y si Open-Meteo se cae, tampoco revienta. */
    public function test_si_el_clima_no_responde_no_revienta(): void
    {
        $this->campusEn(19.4326, -99.1332, 'Campus Centro');

        Http::fake(['api.open-meteo.com/*' => Http::response('', 500)]);

        $this->assertNull($this->climaCrudo('127.0.0.1'));
    }

    /**
     * Lo que da el navegador gana a todo: es lo único que ubica a la PERSONA.
     *
     * La IP y el campus ubican a su red, que en una escuela es la misma para
     * todos. Por eso, cuando alguien da permiso, su ubicación manda aunque haya
     * IP resoluble y campus con coordenadas.
     */
    public function test_lo_que_da_el_navegador_gana_a_la_ip_y_al_campus(): void
    {
        $this->campusEn(19.4326, -99.1332, 'Campus Centro');
        $this->respuestas(ip: ['status' => 'success', 'city' => 'Guadalajara', 'lat' => 20.6597, 'lon' => -103.3496]);

        $clima = $this->clima('189.203.1.1', ['latitud' => 25.6866, 'longitud' => -100.3161]);

        $this->assertSame('tu ubicación', $clima['lugar']);
        $this->assertFalse($clima['aproximado'], 'Lo del navegador es exacto, no aproximado.');
        // Y se le preguntó a Open-Meteo por ESAS coordenadas, no por otras.
        Http::assertSent(fn ($p) => str_contains($p->url(), 'api.open-meteo.com')
            && (string) $p['latitude'] === '25.6866');
    }

    /**
     * Unas coordenadas imposibles no llegan al servicio: se rechazan.
     *
     * Vienen de nuestro propio front, pero es una petición HTTP como cualquier
     * otra: sin validar, un par de números cualquiera acabaría en la llave del
     * cache y en la URL de un servicio de fuera.
     */
    public function test_el_endpoint_rechaza_coordenadas_imposibles(): void
    {
        $this->campusEn(19.4326, -99.1332, 'Campus Centro');
        $this->respuestas();

        $peticion = $this->peticionDe($this->usuarioConAlcance(), '/panel/clima', ['lat' => 95, 'lon' => 0]);

        try {
            app(ClimaController::class)($peticion, app(ClimaDelCampus::class));
            $this->fail('Una latitud de 95 grados no debería pasar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lat', $e->errors());
        }

        Http::assertNotSent(fn ($p) => str_contains($p->url(), 'api.open-meteo.com'));
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    private function campusEn(float $lat, float $lon, string $nombre): void
    {
        $escuela = $this->alumnoInscrito();

        Campus::whereKey($escuela['campus'])->update([
            'nombre' => $nombre,
            'latitud' => $lat,
            'longitud' => $lon,
        ]);
    }

    /**
     * Las respuestas de los tres servicios de fuera.
     *
     * @param  array<string, mixed>|null  $ip  Lo que contesta ip-api, si se le pregunta.
     */
    private function respuestas(?array $ip = null): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response($ip ?? ['status' => 'fail']),
            'api.open-meteo.com/*' => Http::response([
                'current' => [
                    'temperature_2m' => 21.4,
                    'apparent_temperature' => 20.8,
                    'relative_humidity_2m' => 55,
                    'wind_speed_10m' => 9.2,
                    'is_day' => 1,
                    'weather_code' => 0,
                ],
                'daily' => ['time' => ['2026-08-09'], 'weather_code' => [0], 'temperature_2m_max' => [25], 'temperature_2m_min' => [14], 'precipitation_probability_max' => [10]],
            ]),
            'air-quality-api.open-meteo.com/*' => Http::response(['current' => ['us_aqi' => 42]]),
        ]);
    }

    /**
     * @param  array{latitud: float, longitud: float}|null  $coordenadas
     * @return array<string, mixed>
     */
    private function clima(string $ip, ?array $coordenadas = null): array
    {
        $clima = $this->climaCrudo($ip, $coordenadas);

        $this->assertNotNull($clima, 'Se esperaba clima y no llegó ninguno.');

        return $clima;
    }

    /**
     * @param  array{latitud: float, longitud: float}|null  $coordenadas
     * @return array<string, mixed>|null
     */
    private function climaCrudo(string $ip, ?array $coordenadas = null): ?array
    {
        return app(ClimaDelCampus::class)->para($this->usuarioConAlcance(), $ip, $coordenadas);
    }
}
