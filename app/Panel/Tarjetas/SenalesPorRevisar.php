<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\CorridaEvaluacion;
use App\Panel\TarjetaDeModulo;
use App\Panel\TarjetaPanel;
use Illuminate\Database\Eloquent\Builder;

/**
 * La cola de señales sin revisar, por categoría.
 *
 * ── Y CON el dato de cuándo corrió el motor ───────────────────────────────
 * Sin él, una cola vacía se lee como ausencia de riesgo, que es el peor error
 * que este módulo puede inducir. Por eso el pie lo dice siempre, y si el motor
 * lleva días parado la tarjeta se muestra AUNQUE la cola esté vacía: ahí el cero
 * no informa, engaña.
 *
 * ── Sin nombres ───────────────────────────────────────────────────────────
 * Son conteos por categoría. Los nombres están en la bandeja, con su permiso y
 * su alcance por campus — y la categoría sensible además con el suyo.
 *
 * ── El alcance por campus ─────────────────────────────────────────────────
 * Por la OFERTA de la matrícula, igual que la bandeja. Una tarjeta sin recortar
 * pondría la cifra de la escuela entera en el panel de quien coordina un
 * plantel.
 */
class SenalesPorRevisar implements TarjetaDeModulo, TarjetaPanel
{
    /** Días sin evaluar a partir de los cuales la tarjeta avisa. */
    private const DIAS_QUE_PREOCUPAN = 2;

    public function modulo(): string
    {
        return 'permanencia';
    }

    public function clave(): string
    {
        return 'senales-por-revisar';
    }

    public function titulo(): string
    {
        return 'Señales por revisar';
    }

    public function permiso(): ?string
    {
        return 'validar-alertas';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $campus = $usuario->campusVisibles();

        $conteos = Alerta::query()
            ->abiertas()
            ->where('estado_triage', Alerta::NUEVA)
            ->when($campus !== null, fn (Builder $q) => $q->whereHas(
                'matricula.oferta', fn (Builder $o) => $o->whereIn('campus_id', $campus),
            ))
            ->selectRaw('categoria_id, count(*) as c')
            ->groupBy('categoria_id')
            ->pluck('c', 'categoria_id');

        $total = (int) $conteos->sum();

        $corrida = CorridaEvaluacion::query()->latest('iniciada_en')->first();

        $diasSinCorrer = $corrida?->iniciada_en === null
            ? null
            : (int) now()->startOfDay()->diffInDays($corrida->iniciada_en->startOfDay(), absolute: true);

        $parado = $diasSinCorrer === null || $diasSinCorrer > self::DIAS_QUE_PREOCUPAN;

        /*
         * Vacía y con el motor al día: no hay nada que hacer y la tarjeta se
         * calla. Vacía y con el motor PARADO: se muestra, porque ese cero no
         * significa que no haya riesgo — significa que nadie está mirando.
         */
        if ($total === 0 && ! $parado) {
            return null;
        }

        $nombres = CategoriaSenal::query()->whereIn('id', $conteos->keys())
            ->pluck('nombre', 'id');

        return [
            'renglones' => $conteos
                ->sortDesc()
                ->map(fn ($c, $id) => [
                    'etiqueta' => $nombres[$id] ?? 'Sin categoría',
                    'detalle' => null,
                    'valor' => $c === 1 ? '1 señal' : "{$c} señales",
                    'pie' => null,
                    'progreso' => null,
                    'alerta' => false,
                    'enlace' => "/permanencia/alertas?categoria_id={$id}",
                ])
                ->values()
                ->all(),
            'pie' => $parado
                ? ($diasSinCorrer === null
                    ? 'El motor no ha evaluado nunca: esta cola no significa nada todavía.'
                    : "El motor no evalúa desde hace {$diasSinCorrer} días.")
                : 'Evaluado el '.$corrida->iniciada_en->format('Y-m-d H:i').'.',
            'enlace' => '/permanencia/alertas',
        ];
    }
}
