<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La bandeja de ventanilla: papeles que un aspirante subió y nadie ha revisado.
 *
 * ── Se agrupa por ASPIRANTE, no por documento ─────────────────────────────
 * Quien valida abre un expediente y revisa lo que traiga; no atiende archivos
 * sueltos. Un renglón por papel repetiría el mismo nombre cinco veces y haría
 * ver una cola cinco veces más larga de lo que es.
 *
 * ── Se atiende por antigüedad ─────────────────────────────────────────────
 * El orden es la fecha del papel MÁS VIEJO de cada expediente, no cuántos trae:
 * el que lleva doce días esperando no puede quedar debajo del que subió seis
 * hoy.
 *
 * ── Sin alerta por renglón, a propósito ───────────────────────────────────
 * No hay ningún ajuste en la configuración que diga a partir de cuántos días un
 * expediente va retrasado. Cablear «siete» sería inventarle a cada escuela una
 * regla que no eligió; la antigüedad se dice con palabras y el orden ya pone
 * arriba lo que más ha esperado.
 */
class ExpedientesPorValidar implements TarjetaPanel
{
    private const A_LA_VISTA = 8;

    public function clave(): string
    {
        return 'expedientes-por-validar';
    }

    public function titulo(): string
    {
        return 'Expedientes por validar';
    }

    public function permiso(): ?string
    {
        return 'validar-expediente';
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
        return 'M10.125 2.25h-4.5c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125v-9M10.125 2.25h.375a9 9 0 0 1 9 9v.375M10.125 2.25A3.375 3.375 0 0 1 13.5 5.625v1.5c0 .621.504 1.125 1.125 1.125h1.5a3.375 3.375 0 0 1 3.375 3.375M9 15l2.25 2.25L15 12';
    }

    public function datos(Usuario $usuario): ?array
    {
        // Por CLAVE y no por id: `estados_documento` es catálogo de cada
        // escuela, así que el id es del demo y la clave es el contrato.
        $pendiente = EstadoDocumento::query()->where('clave', 'pendiente')->value('id');

        if ($pendiente === null) {
            return null;
        }

        $campus = $usuario->campusVisibles();

        $enEspera = fn () => ExpedienteDocumento::query()
            ->where('estado_documento_id', $pendiente)
            /*
             * El `whereHas` NO es sólo el filtro por campus, y por eso se pone
             * aunque el alcance sea global: dar de baja a un aspirante es un
             * borrado LÓGICO que no se lleva sus papeles, así que sin esto la
             * cola muestra renglones fantasma cuyo enlace responde 404. En el
             * demo hay cinco documentos colgando de un aspirante que ya no
             * existe.
             */
            ->whereHas('aspirante', fn (Builder $q) => $campus === null
                ? $q
                // Un aspirante sin campus no es de nadie: esconderlo de todos lo
                // dejaría sin atender para siempre.
                : $q->where(fn (Builder $w) => $w->whereIn('campus_id', $campus)->orWhereNull('campus_id')));

        $total = (int) $enEspera()->distinct()->count('aspirante_id');

        // Cola de trabajo: la bandeja vacía no se dibuja.
        if ($total === 0) {
            return null;
        }

        $conteos = $enEspera()
            ->groupBy('aspirante_id')
            ->select('aspirante_id', DB::raw('count(*) as papeles'), DB::raw('min(created_at) as desde'))
            ->orderBy('desde')
            ->limit(self::A_LA_VISTA)
            ->get();

        $aspirantes = Aspirante::query()
            ->whereIn('id', $conteos->pluck('aspirante_id'))
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'etapa:id,nombre'])
            ->get()
            ->keyBy('id');

        return [
            'renglones' => $conteos->map(function ($fila) use ($aspirantes) {
                $aspirante = $aspirantes->get($fila->aspirante_id);
                $papeles = (int) $fila->papeles;

                return [
                    'etiqueta' => $aspirante?->persona?->nombreCompleto() ?? 'Aspirante',
                    'valor' => $papeles === 1 ? '1 papel' : "{$papeles} papeles",
                    'detalle' => $aspirante?->etapa?->nombre,
                    'pie' => $this->espera($fila->desde),
                    'progreso' => null,
                    'alerta' => null,
                    'enlace' => '/aspirantes/'.$fila->aspirante_id,
                ];
            })->all(),
            'pie' => $total === 1 ? 'un expediente en espera' : "{$total} expedientes en espera",
            'enlace' => '/aspirantes',
        ];
    }

    private function espera(string $desde): string
    {
        $dias = (int) Carbon::parse($desde)->startOfDay()->diffInDays(now()->startOfDay());

        return $dias === 0 ? 'llegó hoy' : "espera desde hace {$dias} d";
    }
}
