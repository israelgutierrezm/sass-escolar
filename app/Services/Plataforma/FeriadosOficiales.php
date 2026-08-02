<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\DestinoEvento;
use App\Enums\TipoEventoCalendario;
use App\Models\Plataforma\EventoCalendario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Los días festivos oficiales, para no capturarlos a mano cada año.
 *
 * Son los mismos ocho o diez para todo el país y cambian de fecha cada año
 * —el tercer lunes de marzo, el tercer lunes de noviembre—, así que copiarlos
 * a mano es trabajo repetido y con margen de error justo donde más se nota:
 * una escuela que da clase un día que era feriado.
 *
 * ── Llegan como BORRADOR ───────────────────────────────────────────────────
 * No se publican solos. Un feriado oficial no siempre es día sin clases en una
 * escuela particular —hay quien reprograma, quien trabaja el puente— y esa
 * decisión es de la dirección, no de una API. Se importan, se revisan y se
 * publican los que apliquen.
 *
 * ── Y no se duplican ───────────────────────────────────────────────────────
 * Se puede volver a importar el mismo año sin miedo: lo que ya está en esa
 * fecha con ese título no se vuelve a crear. Quien importa dos veces por si
 * acaso no debería terminar con el calendario duplicado.
 */
class FeriadosOficiales
{
    private const SEGUNDOS_ESPERA = 6;

    /**
     * Un almacén propio, fuera de la caché del tenant.
     *
     * Por lo mismo que en {@see ClimaDelCampus}: la caché de tenancy etiqueta
     * todo para aislar por escuela y el driver del proyecto no admite
     * etiquetas. Y los feriados oficiales de México son los mismos para todas
     * las escuelas: no hay nada que aislar.
     */
    private function almacen(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::build([
            'driver' => 'file',
            'path' => storage_path('framework/cache/externos'),
        ]);
    }

    /**
     * Los feriados oficiales de un año, tal como los publica el servicio.
     *
     * @return array<int, array{fecha: string, nombre: string}>|null
     */
    public function delAnio(int $anio, string $pais = 'MX'): ?array
    {
        return $this->almacen()->remember("feriados:{$pais}:{$anio}", now()->addDays(30), function () use ($anio, $pais) {
            try {
                $r = Http::timeout(self::SEGUNDOS_ESPERA)
                    ->get("https://date.nager.at/api/v3/PublicHolidays/{$anio}/{$pais}");

                if (! $r->successful()) {
                    return null;
                }

                return collect($r->json())
                    // `localName` viene en español; `name` está en inglés y es
                    // lo que se colaría en el calendario si uno no mira.
                    ->map(fn (array $f) => [
                        'fecha' => (string) $f['date'],
                        'nombre' => (string) ($f['localName'] ?? $f['name']),
                    ])
                    ->sortBy('fecha')
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /**
     * Mete en el calendario los que falten. Devuelve cuántos creó y cuántos ya estaban.
     *
     * @return array{creados: int, existentes: int, feriados: array<int, array<string, mixed>>}
     */
    public function importar(int $anio, string $pais = 'MX'): array
    {
        $oficiales = $this->delAnio($anio, $pais) ?? [];

        $creados = 0;
        $existentes = 0;
        $detalle = [];

        foreach ($oficiales as $feriado) {
            $yaEsta = EventoCalendario::query()
                ->withTrashed()
                ->whereDate('inicia_en', $feriado['fecha'])
                ->where('titulo', $feriado['nombre'])
                ->exists();

            if ($yaEsta) {
                $existentes++;
                $detalle[] = [...$feriado, 'estado' => 'ya estaba'];

                continue;
            }

            $evento = EventoCalendario::create([
                'tipo' => TipoEventoCalendario::Feriado->value,
                'titulo' => $feriado['nombre'],
                'descripcion' => 'Día festivo oficial. Revisa si aplica en tu escuela antes de publicarlo.',
                'inicia_en' => $feriado['fecha'].' 00:00:00',
                'todo_el_dia' => true,
                'no_laborable' => true,
                // Borrador: que lo confirme la dirección, no una API.
                'publicado' => false,
            ]);

            $evento->destinos()->create([
                'tipo' => DestinoEvento::Todos->value,
                'destino_id' => null,
            ]);

            $creados++;
            $detalle[] = [...$feriado, 'estado' => 'agregado'];
        }

        return ['creados' => $creados, 'existentes' => $existentes, 'feriados' => $detalle];
    }
}
