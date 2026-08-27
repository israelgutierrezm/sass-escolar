<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿Se está procesando la cola de trabajos?
 *
 * ── Por qué existe ────────────────────────────────────────────────────────
 * Por la misma razón que `LatidoDelDespachador`: **una cola sin trabajador NO
 * FALLA**. El trabajo se inserta, nadie lo toma, y no hay excepción ni log ni
 * alerta. La factura simplemente nunca se timbra y quien la emitió cree que sí.
 *
 * Este proyecto lo tuvo así hasta el 2026-08-27: tres sitios encolaban y no
 * había quien procesara, ni en el despachador ni en la documentación de
 * despliegue. No se notó porque esos caminos nunca se habían ejercitado con
 * datos reales.
 *
 * ── Se mira la base CENTRAL ───────────────────────────────────────────────
 * Medido: un trabajo despachado dentro de una escuela cae en la tabla `jobs` de
 * la base central, no en la suya, con el tenant serializado en su payload. Así
 * que la cola es UNA para toda la plataforma y se pregunta en un solo sitio.
 *
 * ── Lo VIEJO es la señal, no lo pendiente ─────────────────────────────────
 * Que haya trabajos esperando es normal: se acaban de encolar. Lo que delata a
 * un trabajador muerto es que el MÁS VIEJO lleve ahí demasiado tiempo. Contar
 * pendientes daría falsos avisos cada vez que alguien timbre un lote.
 */
class EstadoDeLaCola
{
    /**
     * A partir de cuántos minutos esperando se da por atorada.
     *
     * Quince: el trabajador arranca cada minuto, así que un trabajo normal no
     * espera nada. Pero `ArchivarGrabacion` puede tener el trabajador ocupado
     * media hora bajando un video, y durante ese rato lo que venga detrás espera
     * de forma legítima. Quince minutos ya no distingue mal: si el trabajador
     * vive, en quince minutos algo se movió.
     */
    public const TOLERANCIA_MINUTOS = 15;

    /**
     * @return array{
     *     hay_tabla: bool, pendientes: int, en_proceso: int, diferidos: int,
     *     fallidos: int, mas_viejo: ?string, espera_minutos: ?int, atorada: bool
     * }
     */
    public function estado(?int $tolerancia = null): array
    {
        $tolerancia ??= self::TOLERANCIA_MINUTOS;
        $central = config('tenancy.database.central_connection', 'mysql');

        if (! Schema::connection($central)->hasTable('jobs')) {
            return [
                'hay_tabla' => false, 'pendientes' => 0, 'en_proceso' => 0, 'diferidos' => 0,
                'fallidos' => 0, 'mas_viejo' => null, 'espera_minutos' => null, 'atorada' => false,
            ];
        }

        $conexion = DB::connection($central);
        $ahora = now()->timestamp;

        /*
         * Una fila de `jobs` puede estar en TRES situaciones distintas, y
         * confundirlas produce alarmas falsas o silencios peligrosos:
         *
         *   - ESPERANDO: sin reservar y con su turno llegado. Es lo único que
         *     mide si hay quien trabaje.
         *   - EN PROCESO: reservada, o sea que un trabajador la está haciendo.
         *     `markJobAsReserved` escribe `reserved_at` y NO mueve
         *     `available_at`, así que un archivado de media hora sigue
         *     mostrando su hora original: contarlo como espera declaraba la
         *     cola atorada precisamente mientras funcionaba.
         *   - DIFERIDA: sin reservar pero con `available_at` en el futuro. Es
         *     un reintento aguardando su turno —el PAC devolvió un error
         *     pasajero— y no hay nada que arreglar.
         *
         * El corte es el mismo que usa Laravel en
         * `DatabaseQueue::creationTimeOfOldestPendingJob()`.
         */
        $esperando = fn () => $conexion->table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $ahora);

        $pendientes = (int) $esperando()->count();
        $enProceso = (int) $conexion->table('jobs')->whereNotNull('reserved_at')->count();
        $diferidos = (int) $conexion->table('jobs')
            ->whereNull('reserved_at')
            ->where('available_at', '>', $ahora)
            ->count();

        $masViejo = $esperando()->min('available_at');

        /*
         * Con la zona de la aplicación, no en UTC.
         *
         * `createFromTimestamp()` sin zona devuelve UTC, y la espera en minutos
         * sale bien igual —son dos instantes absolutos—, así que el defecto no
         * asoma por ahí: lo que sale mal es la MARCA que se imprime. Se leía «el
         * más viejo desde hace 180 minutos (18:04)» con el reloj del servidor en
         * las 15:04, o sea una hora en el futuro. Es la misma trampa que ya se
         * cobró `hoyLocal()` en el frontend.
         */
        $desde = $masViejo === null
            ? null
            : Carbon::createFromTimestamp($masViejo, config('app.timezone'));

        $espera = $desde === null ? null : (int) $desde->diffInMinutes(now());

        $fallidos = Schema::connection($central)->hasTable('failed_jobs')
            ? (int) $conexion->table('failed_jobs')->count()
            : 0;

        return [
            'hay_tabla' => true,
            'pendientes' => $pendientes,
            'en_proceso' => $enProceso,
            'diferidos' => $diferidos,
            'fallidos' => $fallidos,
            'mas_viejo' => $desde?->toDateTimeString(),
            'espera_minutos' => $espera,
            'atorada' => $espera !== null && $espera > $tolerancia,
        ];
    }
}
