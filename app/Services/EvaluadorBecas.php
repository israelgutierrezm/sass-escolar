<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\BecaAlumnoMovimiento;
use App\Models\ControlEscolar\Ciclo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Aplica las reglas que hacen que una beca se conserve, se suspenda o se pierda.
 *
 * Son dos evaluaciones distintas y en momentos distintos:
 *
 *  - **Por atraso** (`evaluarAtrasos`): corre seguido, junto con los recargos.
 *    Si el alumno se pasó de la fecha límite y su beca exige puntualidad, ese
 *    cargo pierde el descuento —solo ese, los demás siguen con beca— o pierde la
 *    beca por completo, según cómo esté configurada.
 *  - **Por promedio** (`evaluarRenovacion`): corre al cerrar el ciclo. Se compara
 *    el promedio del CICLO QUE TERMINA contra el mínimo de la beca para decidir
 *    si se renueva para el siguiente.
 *
 * Todo movimiento queda en la bitácora: una beca que se cae le cuesta dinero a
 * una familia y alguien va a preguntar por qué.
 */
class EvaluadorBecas
{
    public function __construct(
        private readonly GeneradorAdeudos $generador,
    ) {}

    /**
     * Revisa los cargos vencidos y castiga las becas que exigen puntualidad.
     *
     * @return array{suspendidas: int, perdidas: int}
     */
    public function evaluarAtrasos(?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::today();
        $suspendidas = 0;
        $perdidas = 0;

        $vencidos = Adeudo::query()
            ->whereIn('estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL])
            ->whereDate('fecha_vencimiento', '<', $hoy->toDateString())
            ->whereNotNull('matricula_oferta_id')
            ->with('ajustes')
            ->get();

        foreach ($vencidos as $adeudo) {
            // Solo importan los cargos que traían beca: si no la tenían, no hay
            // nada que castigar.
            $ajusteBeca = $adeudo->ajustes->firstWhere('tipo', AdeudoAjuste::TIPO_BECA);

            if ($ajusteBeca === null) {
                continue;
            }

            $becaAlumno = BecaAlumno::with('beca')->find($ajusteBeca->origen_id);
            $beca = $becaAlumno?->beca;

            if ($becaAlumno === null || $beca === null || ! $beca->requiere_pago_puntual) {
                continue;
            }

            $limite = CarbonImmutable::parse($adeudo->fecha_vencimiento)->addDays($beca->dias_tolerancia);

            if ($hoy->lessThanOrEqualTo($limite)) {
                continue;
            }

            if ($beca->efecto_atraso === Beca::ATRASO_SUSPENDE_PERIODO) {
                $this->quitarBecaDelCargo($adeudo, $ajusteBeca, $becaAlumno);
                $suspendidas++;

                continue;
            }

            if ($beca->efecto_atraso === Beca::ATRASO_PIERDE) {
                $this->perder($becaAlumno, "Atraso en el cargo del {$adeudo->fecha_vencimiento}");
                $perdidas++;
            }
        }

        return ['suspendidas' => $suspendidas, 'perdidas' => $perdidas];
    }

    /**
     * Quita el descuento de UN cargo (el atrasado) y recompone su total. La beca
     * sigue activa para los demás: es una suspensión de periodo, no un castigo
     * permanente.
     */
    private function quitarBecaDelCargo(Adeudo $adeudo, AdeudoAjuste $ajuste, BecaAlumno $becaAlumno): void
    {
        DB::transaction(function () use ($adeudo, $ajuste, $becaAlumno) {
            $ajuste->delete();

            $descuentos = abs((float) $adeudo->ajustes()
                ->whereIn('tipo', [AdeudoAjuste::TIPO_BECA, AdeudoAjuste::TIPO_DESCUENTO])
                ->sum('monto'));

            $adeudo->update([
                'monto_descuentos' => $descuentos,
                'monto_total' => max(0, round(
                    (float) $adeudo->monto - $descuentos + (float) $adeudo->monto_recargos, 2
                )),
            ]);

            $this->registrar(
                $becaAlumno,
                BecaAlumnoMovimiento::SUSPENDIDA,
                "Se cobró completo el cargo con vencimiento {$adeudo->fecha_vencimiento} por pago fuera de tiempo."
            );
        });
    }

    /** La beca se pierde definitivamente. */
    public function perder(BecaAlumno $becaAlumno, string $motivo): void
    {
        DB::transaction(function () use ($becaAlumno, $motivo) {
            $becaAlumno->update([
                'estatus' => BecaAlumno::PERDIDA,
                'vigente_hasta' => now()->toDateString(),
            ]);

            $this->registrar($becaAlumno, BecaAlumnoMovimiento::PERDIDA, $motivo);

            // Los cargos que aún no se pagan dejan de llevar el descuento.
            if ($becaAlumno->matricula !== null) {
                $this->generador->recalcularPendientes($becaAlumno->matricula);
            }
        });
    }

    /**
     * Decide, al cerrar un ciclo, qué becas se renuevan para el siguiente.
     *
     * Se evalúa contra el promedio del ciclo que termina. Las que no alcanzan el
     * mínimo se marcan `no_renovada` o se pierden, según la beca; las demás
     * quedan `por_renovar` para que alguien las confirme —renovar sola una beca
     * sin que nadie la autorice sería regalar dinero de la escuela.
     *
     * @param  array<int, float>  $promedios  matricula_oferta_id => promedio del ciclo
     * @return array{por_renovar: int, no_renovadas: int, perdidas: int}
     */
    public function evaluarRenovacion(Ciclo $cicloQueTermina, array $promedios): array
    {
        $porRenovar = 0;
        $noRenovadas = 0;
        $perdidas = 0;

        $becas = BecaAlumno::query()
            ->where('ciclo_id', $cicloQueTermina->id)
            ->activas()
            ->with('beca')
            ->get();

        foreach ($becas as $becaAlumno) {
            $beca = $becaAlumno->beca;

            if ($beca === null || ! $beca->requiere_renovacion) {
                continue;
            }

            $promedio = $promedios[$becaAlumno->matricula_oferta_id] ?? null;
            $minimo = $beca->promedio_minimo !== null ? (float) $beca->promedio_minimo : null;

            $reprueba = $minimo !== null && $promedio !== null && $promedio < $minimo;

            if ($reprueba && $beca->efecto_promedio === Beca::PROMEDIO_PIERDE) {
                $this->perder($becaAlumno, "Promedio {$promedio} por debajo del mínimo {$minimo}.");
                $perdidas++;

                continue;
            }

            if ($reprueba && $beca->efecto_promedio === Beca::PROMEDIO_NO_RENUEVA) {
                $becaAlumno->update(['estatus' => BecaAlumno::PERDIDA, 'promedio_evaluado' => $promedio]);
                $this->registrar(
                    $becaAlumno,
                    BecaAlumnoMovimiento::NO_RENOVADA,
                    "No se renueva: promedio {$promedio} por debajo del mínimo {$minimo}."
                );
                $noRenovadas++;

                continue;
            }

            $becaAlumno->update(['estatus' => BecaAlumno::POR_RENOVAR, 'promedio_evaluado' => $promedio]);
            $this->registrar(
                $becaAlumno,
                BecaAlumnoMovimiento::SUSPENDIDA,
                'Candidata a renovación'.($promedio !== null ? " (promedio {$promedio})" : '').'.'
            );
            $porRenovar++;
        }

        return ['por_renovar' => $porRenovar, 'no_renovadas' => $noRenovadas, 'perdidas' => $perdidas];
    }

    /** Deja constancia del movimiento con su autor. */
    public function registrar(BecaAlumno $becaAlumno, string $accion, ?string $detalle = null): void
    {
        $usuario = Auth::user();

        BecaAlumnoMovimiento::create([
            'beca_alumno_id' => $becaAlumno->id,
            'accion' => $accion,
            'detalle' => $detalle,
            'realizado_por' => $usuario?->getKey(),
            'realizado_por_nombre' => $usuario?->persona?->nombreCompleto() ?: $usuario?->usuario,
        ]);
    }
}
