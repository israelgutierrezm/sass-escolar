<?php

declare(strict_types=1);

namespace App\Services\Finanzas;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\ConvenioDescuento;
use App\Services\EvaluadorBecas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El acuerdo con un tercero, y lo que pasa cuando se acaba.
 *
 * ── Terminar el convenio CIERRA sus becas ──────────────────────────────────
 * Es lo único que este servicio agrega sobre el motor de becas, y es la razón
 * de que el convenio exista como tabla: hoy cada otorgamiento tiene sus propias
 * fechas y nada las cierra juntas, así que un acuerdo terminado seguiría
 * descontando hasta que alguien recorriera los otorgamientos uno por uno.
 *
 * ── Y se cierra con un ACTO, no con una condición escondida ────────────────
 * La alternativa era que `aplicaEn()` preguntara además por el convenio. Se
 * descartó: metería una condición en el camino caliente del cálculo de cada
 * cargo, y —peor— dejaría las becas ACTIVAS en la base mientras no descuentan,
 * que es un estado que nadie puede explicar mirando la ficha del alumno. Aquí
 * las becas quedan PERDIDAS, con su bitácora, y los cargos pendientes se
 * recomponen en el momento.
 *
 * ── Vencer y estar terminado son cosas distintas ───────────────────────────
 * Un convenio puede tener la fecha pasada y seguir con estatus vigente hasta
 * que el barrido nocturno lo cierre. Confundirlas dejaría descuentos corriendo
 * después de que el acuerdo terminó; por eso `porVencer()` existe y el comando
 * la usa.
 */
class ConvenioDeDescuento
{
    public function __construct(private readonly EvaluadorBecas $evaluador) {}

    /**
     * Da por terminado el convenio y cierra sus becas.
     *
     * @return int cuántos otorgamientos se cerraron
     */
    public function terminar(ConvenioDescuento $convenio, string $motivo): int
    {
        AvisoParaElUsuario::aMenosQue(
            $convenio->estaVigente(),
            422,
            'Ese convenio ya está terminado.',
        );

        return DB::transaction(function () use ($convenio, $motivo) {
            $cerrados = 0;

            foreach ($this->otorgamientosVivos($convenio) as $otorgada) {
                /*
                 * Se reusa `perder`, que es lo que ya sabe dar de baja una beca
                 * y recomponer sus cargos pendientes. Escribirlo otra vez aquí
                 * sería la segunda implementación de «quitar una beca», y la que
                 * se olvidara de recalcular dejaría descuentos aplicados sobre
                 * cargos que ya no los llevan.
                 */
                $this->evaluador->perder($otorgada, "Terminó el convenio «{$convenio->nombre}»: {$motivo}");
                $cerrados++;
            }

            $convenio->update([
                'estatus' => ConvenioDescuento::TERMINADO,
                'terminado_en' => now(),
                'motivo_termino' => $motivo,
            ]);

            return $cerrados;
        });
    }

    /**
     * Cierra los convenios a los que se les pasó la fecha.
     *
     * @return array{convenios: int, becas: int}
     */
    public function cerrarVencidos(?string $hoy = null): array
    {
        $convenios = 0;
        $becas = 0;

        foreach (ConvenioDescuento::query()->porVencer($hoy)->get() as $convenio) {
            $becas += $this->terminar(
                $convenio,
                'Venció el '.$convenio->vigente_hasta?->toDateString().'.',
            );
            $convenios++;
        }

        return ['convenios' => $convenios, 'becas' => $becas];
    }

    /**
     * Hasta cuándo puede valer un otorgamiento de este convenio.
     *
     * Lo capa el fin del acuerdo: una beca de convenio que durara más que el
     * convenio seguiría descontando después de que la relación terminó, y el
     * cierre nocturno tendría que perseguirla. Capándola, el mecanismo que ya
     * existe —`aplicaEn()` mira `vigente_hasta`— hace el trabajo solo.
     */
    public function topeDeVigencia(ConvenioDescuento $convenio, ?string $pedido): string
    {
        $fin = $convenio->vigente_hasta->toDateString();

        return $pedido === null || $pedido > $fin ? $fin : $pedido;
    }

    /**
     * Qué se lleva descontado el convenio, y quiénes son sus beneficiarios.
     *
     * El importe se MIDE de `adeudo_ajustes`, igual que el presupuesto de
     * becas: un renglón por cada cargo que el descuento movió, auditable uno
     * por uno. Estimarlo exigiría inventar cuántos cargos faltan.
     *
     * @return array{beneficiarios: int, descontado: float, becas: int}
     */
    public function panorama(ConvenioDescuento $convenio): array
    {
        $otorgadas = BecaAlumno::query()
            ->whereIn('beca_id', $convenio->becas()->select('id'))
            ->select('id');

        return [
            'becas' => $convenio->becas()->count(),
            'beneficiarios' => (clone $otorgadas)->count(),
            'descontado' => round(abs((float) AdeudoAjuste::query()
                ->where('tipo', AdeudoAjuste::TIPO_BECA)
                ->whereIn('origen_id', $otorgadas)
                ->sum('monto')), 2),
        ];
    }

    /**
     * Por qué no se puede otorgar bajo este convenio, o null si sí se puede.
     */
    public function motivoParaNoOtorgar(ConvenioDescuento $convenio, ?string $hoy = null): ?string
    {
        if (! $convenio->estaVigente()) {
            return 'Ese convenio ya está terminado.';
        }

        if ($convenio->estaVencido($hoy)) {
            return 'Ese convenio venció el '.$convenio->vigente_hasta?->toDateString().'.';
        }

        return null;
    }

    /** @return Collection<int, BecaAlumno> */
    private function otorgamientosVivos(ConvenioDescuento $convenio)
    {
        return BecaAlumno::query()
            ->whereIn('beca_id', $convenio->becas()->select('id'))
            ->whereIn('estatus', [BecaAlumno::ACTIVA, BecaAlumno::POR_AUTORIZAR, BecaAlumno::SUSPENDIDA])
            ->get();
    }
}
