<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Factura;
use App\Models\Finanzas\PeriodoFiscal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Cierra un mes fiscal y decide qué deja de poderse tocar.
 *
 * ── Qué impide, que no es lo obvio ─────────────────────────────────────────
 * Aquí una factura se emite siempre con la fecha de hoy —se factura dinero ya
 * cobrado—, así que cerrar no puede significar «que no entren comprobantes con
 * fecha vieja»: eso no puede pasar. Lo que sí puede es que alguien CANCELE un
 * comprobante de un mes ya declarado, y eso cambia hacia atrás un número que la
 * escuela ya presentó.
 *
 * ── Y lo que NO impide, a propósito ────────────────────────────────────────
 * La nota de crédito. Se emite con fecha de hoy y pertenece al periodo de hoy,
 * así que corrige el mes cerrado sin tocarlo — que es lo que hace un contador
 * cuando el mes ya se declaró. Cerrar no bloquea la corrección: la empuja al
 * instrumento correcto.
 */
class CierreFiscal
{
    /**
     * Los totales de un mes, calculados AHORA.
     *
     * @return array{comprobantes: int, ingresos: float, egresos: float}
     */
    public function totales(int $anio, int $mes): array
    {
        // Sólo lo VIGENTE. Una cancelada no forma parte de lo declarado, y
        // sumarla haría que el cierre afirmara un ingreso que la escuela nunca
        // reportó.
        $consulta = Factura::query()
            ->timbradas()
            ->whereYear('fecha_timbrado', $anio)
            ->whereMonth('fecha_timbrado', $mes);

        return [
            'comprobantes' => (clone $consulta)->count(),
            'ingresos' => (float) (clone $consulta)->deIngreso()->sum('total'),
            // Los egresos van APARTE y no restados: son un dato propio del mes
            // —cuánto se acreditó— y fundirlos en un neto escondería la mitad de
            // lo que hay que declarar.
            'egresos' => (float) (clone $consulta)->where('tipo', Factura::TIPO_EGRESO)->sum('total'),
        ];
    }

    public function cerrar(int $anio, int $mes): PeriodoFiscal
    {
        $fin = Carbon::create($anio, $mes, 1)->endOfMonth();

        // No se cierra un mes que todavía no termina: quedaría cerrado con
        // facturas por emitir, y las de mañana caerían dentro de un periodo que
        // ya nadie puede corregir.
        if ($fin->isFuture()) {
            throw new RuntimeException(
                'Ese mes todavía no termina. Cerrarlo dejaría dentro las facturas que faltan por emitir.'
            );
        }

        $periodo = $this->periodo($anio, $mes);

        if ($periodo->estaCerrado()) {
            throw new RuntimeException('Ese periodo ya está cerrado.');
        }

        $totales = $this->totales($anio, $mes);

        $periodo->fill($totales + [
            'cerrado_en' => Carbon::now(),
            // Se limpia el rastro de la reapertura anterior: lo que describe es
            // por qué se volvió a abrir, y con el mes cerrado otra vez esa
            // afirmación ya no vale.
            'reabierto_en' => null,
            'motivo_reapertura' => null,
        ])->save();

        return $periodo;
    }

    public function reabrir(int $anio, int $mes, string $motivo): PeriodoFiscal
    {
        $periodo = $this->periodo($anio, $mes);

        if (! $periodo->estaCerrado()) {
            throw new RuntimeException('Ese periodo no está cerrado.');
        }

        // El motivo es obligatorio y no es burocracia: reabrir un mes declarado
        // habilita cambiar un número que ya se presentó, y dentro de un año es
        // lo único que explica por qué se hizo.
        $periodo->fill([
            'cerrado_en' => null,
            'reabierto_en' => Carbon::now(),
            'motivo_reapertura' => $motivo,
        ])->save();

        return $periodo;
    }

    /**
     * El periodo cerrado al que pertenece una factura, o null si se puede
     * tocar.
     *
     * Un comprobante sin fecha de timbrado no pertenece a ningún periodo: no es
     * fiscal todavía, y un borrador se corrige o se borra sin que el SAT se
     * entere.
     */
    public function periodoCerradoDe(Factura $factura): ?PeriodoFiscal
    {
        if ($factura->fecha_timbrado === null) {
            return null;
        }

        $periodo = PeriodoFiscal::query()
            ->where('anio', (int) $factura->fecha_timbrado->format('Y'))
            ->where('mes', (int) $factura->fecha_timbrado->format('n'))
            ->first();

        return $periodo?->estaCerrado() === true ? $periodo : null;
    }

    /**
     * Los últimos meses con sus totales y su estado, para la pantalla.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panorama(int $meses = 12): array
    {
        $cursor = Carbon::now()->startOfMonth();
        $filas = [];

        for ($i = 0; $i < $meses; $i++) {
            $anio = (int) $cursor->format('Y');
            $mes = (int) $cursor->format('n');
            $periodo = PeriodoFiscal::query()->where('anio', $anio)->where('mes', $mes)->first();
            $totales = $this->totales($anio, $mes);

            $filas[] = [
                'anio' => $anio,
                'mes' => $mes,
                'etiqueta' => ucfirst($cursor->translatedFormat('F \d\e Y')),
                'cerrado' => $periodo?->estaCerrado() === true,
                'cerrado_en' => $periodo?->cerrado_en?->toDateTimeString(),
                'motivo_reapertura' => $periodo?->motivo_reapertura,
                'reabierto_en' => $periodo?->reabierto_en?->toDateTimeString(),
                // El mes en curso no se puede cerrar: se dice aquí para que la
                // pantalla no ofrezca un botón que el servidor va a rechazar.
                'en_curso' => $cursor->copy()->endOfMonth()->isFuture(),
                'ahora' => $totales,
                // Lo que se congeló al cerrar. Enseñar las dos cifras es lo que
                // permite ver que algo cambió DESPUÉS del cierre.
                'al_cerrar' => $periodo?->estaCerrado() === true ? [
                    'comprobantes' => (int) $periodo->comprobantes,
                    'ingresos' => (float) $periodo->ingresos,
                    'egresos' => (float) $periodo->egresos,
                ] : null,
            ];

            $cursor->subMonth();
        }

        return $filas;
    }

    private function periodo(int $anio, int $mes): PeriodoFiscal
    {
        try {
            return PeriodoFiscal::query()->firstOrCreate(['anio' => $anio, 'mes' => $mes]);
        } catch (UniqueConstraintViolationException) {
            // Dos peticiones a la vez: el `firstOrCreate` no es atómico y el
            // único de la base es lo que de verdad lo impide. La segunda se
            // queda con la fila que ganó la primera.
            return PeriodoFiscal::query()->where('anio', $anio)->where('mes', $mes)->firstOrFail();
        }
    }
}
