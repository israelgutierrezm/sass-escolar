<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Promocion\Comision;
use App\Models\Promocion\ReglaComision;
use Illuminate\Database\QueryException;

/**
 * Paga al promotor cuando su prospecto se inscribe.
 *
 * DECISIÓN DEL CLIENTE: se devenga al INSCRIBIRSE, no al capturar. Se paga por
 * resultado; devengar al registrar premiaría capturar nombres y llenaría el CRM
 * de prospectos basura.
 *
 * Se llama DENTRO de la transacción de conversión, como el religador de
 * finanzas: una comisión sin matrícula, o una matrícula sin la comisión que le
 * correspondía, son dos formas de que después no cuadre la nómina de promoción.
 *
 * Silencioso por diseño: si el aspirante no trae promotor titular, o no hay
 * regla vigente, no devenga y no falla. La conversión de un alumno NO debe
 * romperse porque falte configurar comisiones — la mayoría de las escuelas no
 * las usa.
 */
class DevengadorComisiones
{
    /** De más específico a más general. */
    private const PRECEDENCIA = [
        ReglaComision::APLICA_OFERTA,
        ReglaComision::APLICA_CARRERA,
        ReglaComision::APLICA_GLOBAL,
    ];

    /**
     * @return Comision|null la comisión devengada, o null si no procedía
     */
    public function devengar(Aspirante $aspirante, MatriculaOferta $matricula): ?Comision
    {
        $titular = $this->promotorTitular($aspirante);

        if ($titular === null) {
            return null;
        }

        $regla = $this->reglaPara($matricula);

        if ($regla === null) {
            return null;
        }

        $monto = $this->calcular($regla, $matricula);

        // Una regla que da cero no genera renglón: una comisión de $0 en el
        // estado de cuenta del promotor es ruido que hay que explicar.
        if ($monto <= 0) {
            return null;
        }

        try {
            return Comision::create([
                'persona_id' => $titular,
                'aspirante_id' => $aspirante->id,
                'matricula_oferta_id' => $matricula->id,
                'regla_id' => $regla->id,
                'monto' => $monto,
                'estatus' => Comision::ESTATUS_DEVENGADA,
                'devengada_en' => now(),
            ]);
        } catch (QueryException $e) {
            // 23000: ya existía la comisión de esta matrícula para este asesor.
            // Pasa si la conversión se reintenta. Es exactamente lo que el
            // índice único existe para evitar, así que no es un error.
            if ($e->getCode() === '23000') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * El asesor que responde por el prospecto. Solo el TITULAR devenga: un
     * aspirante puede tener varios asesores encima y la comisión es una.
     */
    private function promotorTitular(Aspirante $aspirante): ?int
    {
        return $aspirante->asesores()
            ->wherePivot('titular', true)
            ->value('asesores.persona_id');
    }

    /** Gana la regla vigente más específica: oferta → carrera → global. */
    private function reglaPara(MatriculaOferta $matricula): ?ReglaComision
    {
        $matricula->loadMissing('oferta');
        $oferta = $matricula->oferta;

        $identificador = [
            ReglaComision::APLICA_OFERTA => $oferta?->id,
            ReglaComision::APLICA_CARRERA => $oferta?->carrera_id,
            ReglaComision::APLICA_GLOBAL => null,
        ];

        foreach (self::PRECEDENCIA as $tipo) {
            $id = $identificador[$tipo];

            if ($tipo !== ReglaComision::APLICA_GLOBAL && $id === null) {
                continue;
            }

            $consulta = ReglaComision::query()->vigentes()->where('aplica_a_tipo', $tipo);

            $consulta = $id === null
                ? $consulta->whereNull('aplica_a_id')
                : $consulta->where('aplica_a_id', $id);

            $regla = $consulta->orderByDesc('vigente_desde')->orderByDesc('id')->first();

            if ($regla !== null) {
                return $regla;
            }
        }

        return null;
    }

    /**
     * Monto fijo, o porcentaje sobre el concepto que diga la regla.
     *
     * El porcentaje se calcula sobre el monto BASE del adeudo, no sobre el
     * total: si al alumno se le dio una beca, el promotor no debería cobrar
     * menos por un descuento que no decidió él. Y si el concepto todavía no se
     * le generó, el porcentaje no tiene sobre qué aplicarse y devuelve cero.
     */
    private function calcular(ReglaComision $regla, MatriculaOferta $matricula): float
    {
        if ($regla->modo === ReglaComision::MODO_MONTO_FIJO) {
            return round((float) $regla->valor, 2);
        }

        $base = Adeudo::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->when(
                $regla->concepto_id !== null,
                fn ($q) => $q->where('concepto_id', $regla->concepto_id)
            )
            ->sum('monto');

        return round((float) $base * (float) $regla->valor / 100, 2);
    }
}
