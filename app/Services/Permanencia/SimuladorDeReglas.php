<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\RegistroProveedores;

/**
 * Previsualiza a quién marcaría una regla ANTES de encenderla.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El sesgo dominante de este módulo es de CALIBRACIÓN: un umbral mal puesto o
 * llena la cola de falsos positivos —y a la tercera nadie la mira— o no marca a
 * nadie. Las reglas nacen APAGADAS justamente para poder afinarlas antes; lo que
 * faltaba era con qué. Esto lo da: cuántas mediciones dispararían hoy, cuántas
 * se quedan SIN DATOS (la otra mitad de la calibración: una regla que no marca a
 * nadie puede ser que nadie la cumpla o que no haya con qué medirla) y una
 * muestra de a quién.
 *
 * ── NO ESCRIBE NADA ─────────────────────────────────────────────────────────
 * No toca `alertas`, ni `corridas_evaluacion`, ni las tablas de origen. Es una
 * previsualización, como `GeneradorMatricula::previsualizar` no consume folio.
 * Reusa el VEREDICTO del motor ({@see MotorDeEvaluacion::veredictoDe}) y su
 * `umbralDe`, así que lo que dice que dispararía es exactamente lo que el motor
 * de madrugada levantaría —salvo el enfriamiento y la deduplicación, que son
 * historia y no cambian a quién señala la regla HOY—.
 *
 * ── Respeta el ALCANCE de quien simula ──────────────────────────────────────
 * Sólo recorre matrículas de los campus que su rol alcanza, y de una CATEGORÍA
 * SENSIBLE devuelve el conteo pero NO la muestra de nombres: calibrar necesita
 * saber CUÁNTOS, no exponer quiénes tienen un problema de dinero. Mismo criterio
 * que la bandeja.
 */
class SimuladorDeReglas
{
    /** Tope de matrículas recorridas: esto corre en una petición, no de madrugada. */
    public const TOPE = 3000;

    private const MUESTRA = 15;

    public function __construct(
        private readonly RegistroProveedores $proveedores,
        private readonly MotorDeEvaluacion $motor,
    ) {}

    /**
     * @param  array<int, int>|null  $campus  campus que alcanza quien simula; null = todos
     * @return array<string, mixed>
     */
    public function simular(ReglaAlerta $regla, ReglaAlertaVersion $version, ?array $campus): array
    {
        $proveedor = $this->proveedores->de($regla->proveedor);

        if ($proveedor === null) {
            return ['error' => 'Esa métrica no tiene proveedor: no se puede simular.'];
        }

        $sensible = (bool) $regla->categoria?->sensible;

        $conteo = [MotorDeEvaluacion::DISPARA => 0, MotorDeEvaluacion::NO_DISPARA => 0, MotorDeEvaluacion::SIN_DATOS => 0];
        $muestra = [];
        $marcados = [];   // matrículas distintas con al menos un disparo
        $alcanzadas = 0;  // matrículas que la regla alcanza (el denominador honesto)
        $vistas = 0;
        $capado = false;

        MatriculaOferta::query()
            ->whereHas('oferta', fn ($o) => $campus === null ? $o : $o->whereIn('campus_id', $campus))
            ->with([
                'oferta:id,campus_id,programa_academico_id,plan_id,modalidad',
                'oferta.programaAcademico:id,nivel_estudios_id',
                'persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($lote) use (
                &$conteo, &$muestra, &$marcados, &$alcanzadas, &$vistas, &$capado,
                $regla, $version, $proveedor, $sensible
            ) {
                foreach ($lote as $matricula) {
                    if ($vistas >= self::TOPE) {
                        $capado = true;

                        return false;
                    }

                    $vistas++;

                    if (! $regla->alcanzaA($matricula)) {
                        continue;
                    }

                    $alcanzadas++;
                    $umbral = $this->motor->umbralDe($version, $matricula);

                    foreach ($proveedor->medir($matricula, $version->metrica, $version) as $medicion) {
                        $veredicto = $this->motor->veredictoDe($version, $medicion, $umbral);
                        $conteo[$veredicto]++;

                        if ($veredicto !== MotorDeEvaluacion::DISPARA) {
                            continue;
                        }

                        $marcados[$matricula->id] = true;

                        if (! $sensible && count($muestra) < self::MUESTRA) {
                            $muestra[] = [
                                'matricula' => $matricula->matricula,
                                'alumno' => $matricula->persona?->nombreCompleto(),
                                'valor' => $medicion->valor,
                            ];
                        }
                    }
                }

                return true;
            });

        $mediciones = array_sum($conteo);

        return [
            'dispara' => $conteo[MotorDeEvaluacion::DISPARA],
            'no_dispara' => $conteo[MotorDeEvaluacion::NO_DISPARA],
            'sin_datos' => $conteo[MotorDeEvaluacion::SIN_DATOS],
            'mediciones' => $mediciones,
            'alcanzadas' => $alcanzadas,
            'alumnos_marcados' => count($marcados),
            // La proporción sin datos es la señal de COBERTURA: null cuando no
            // hubo ninguna medición, no cero —cero afirmaría que todo se midió—.
            'sin_datos_pct' => $mediciones > 0
                ? (int) round($conteo[MotorDeEvaluacion::SIN_DATOS] * 100 / $mediciones)
                : null,
            'muestra' => $muestra,
            'sensible' => $sensible,
            'capado' => $capado,
            'tope' => self::TOPE,
        ];
    }
}
