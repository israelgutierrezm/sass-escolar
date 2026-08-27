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
     *     hay_tabla: bool, pendientes: int, fallidos: int,
     *     mas_viejo: ?string, espera_minutos: ?int, atorada: bool
     * }
     */
    public function estado(?int $tolerancia = null): array
    {
        $tolerancia ??= self::TOLERANCIA_MINUTOS;
        $central = config('tenancy.database.central_connection', 'mysql');

        if (! Schema::connection($central)->hasTable('jobs')) {
            return [
                'hay_tabla' => false, 'pendientes' => 0, 'fallidos' => 0,
                'mas_viejo' => null, 'espera_minutos' => null, 'atorada' => false,
            ];
        }

        $conexion = DB::connection($central);

        $pendientes = (int) $conexion->table('jobs')->count();

        /*
         * `available_at` y no `created_at`: un trabajo con reintento espera a
         * propósito hasta su siguiente turno, y contarlo como atorado desde que
         * se creó daría una alarma cada vez que el PAC devuelva un error
         * transitorio — que es exactamente cuando NO hay que distraer a nadie.
         */
        $masViejo = $conexion->table('jobs')->min('available_at');

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
            'fallidos' => $fallidos,
            'mas_viejo' => $desde?->toDateTimeString(),
            'espera_minutos' => $espera,
            'atorada' => $espera !== null && $espera > $tolerancia,
        ];
    }
}
