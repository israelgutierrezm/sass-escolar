<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Permanencia\AvisoPermanencia;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\CorridaEvaluacion;
use App\Models\Permanencia\ReglaAlerta;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Carbon\CarbonImmutable;

/**
 * ¿Este módulo está haciendo su trabajo?
 *
 * ── Las tres cosas que fallan CALLADAS ─────────────────────────────────────
 *  1. **El motor deja de correr.** La bandeja se queda vacía y una cola vacía se
 *     lee como ausencia de riesgo, que es el peor error que este módulo puede
 *     inducir. La bandeja ya avisa a quien la abre; esto lo dice al servidor.
 *  2. **Una regla revienta.** Las demás siguen —cada una va aislada— así que
 *     nada falla a la vista, y esa señal deja de levantarse para siempre.
 *  3. **Los avisos dejan de salir.** El motor puede estar corriendo y el
 *     comando de las 07:45 no; nadie se entera, porque un aviso que no llega no
 *     se echa de menos.
 *
 * Va en un servicio y no dentro del comando porque lo preguntan dos: el
 * `scheduler:estado` y —en la fase que lo pida— un tablero. Escrito dos veces,
 * uno diría que todo va bien mientras el otro no.
 */
class EstadoDePermanencia
{
    /** A partir de cuántos días sin evaluar se da por caído. */
    public const DIAS_PARA_ALARMAR = 2;

    /**
     * @return array<string, mixed>
     */
    public function estado(?CarbonImmutable $ahora = null): array
    {
        $momento = $ahora ?? CarbonImmutable::now();

        /*
         * Si el módulo está apagado no hay nada que revisar, y decir «lleva
         * nueve días sin evaluar» de una escuela que no lo usa es ruido que
         * enseña a ignorar el informe.
         */
        if (! app(ModulosDeLaEscuela::class)->activo('permanencia')) {
            return ['aplica' => false];
        }

        $corrida = CorridaEvaluacion::query()->latest('iniciada_en')->first();

        $reglasActivas = ReglaAlerta::query()->where('activa', true)->count();

        $ultimoAviso = AvisoPermanencia::query()->max('emitida_en');

        return [
            'aplica' => true,
            'reglas_activas' => $reglasActivas,
            'nunca_corrio' => $corrida === null,
            'ultima_corrida' => $corrida?->iniciada_en?->format('Y-m-d H:i'),
            'hace_dias' => $corrida === null ? null : (int) $momento->startOfDay()
                ->diffInDays($corrida->iniciada_en->startOfDay(), absolute: true),
            /*
             * Las reglas que reventaron en la ÚLTIMA corrida, no en cualquiera:
             * una que falló hace un mes y ya se arregló no tiene por qué seguir
             * saliendo con error. Es un estado de HOY.
             */
            'reglas_rotas' => $this->reglasRotas($corrida),
            'sla_vencido' => CasoPermanencia::query()->slaVencido($momento->toDateTimeString())->count(),
            'sin_asignar' => CasoPermanencia::query()->sinAsignar()->count(),
            'ultimo_aviso' => $ultimoAviso === null ? null
                : CarbonImmutable::parse($ultimoAviso)->format('Y-m-d H:i'),
        ];
    }

    /**
     * ¿Hay algo que deba hacer FALLAR al comando?
     *
     * Sólo las reglas rotas. Un caso con el plazo vencido es un asunto de la
     * escuela y sale en su bandeja; hacer fallar la vigilancia del servidor por
     * eso enseñaría a ignorar la alarma, que es exactamente cómo se pierde.
     *
     * @param  array<string, mixed>  $estado
     */
    public function hayFalla(array $estado): bool
    {
        if (($estado['aplica'] ?? false) === false) {
            return false;
        }

        if ($estado['reglas_rotas'] !== []) {
            return true;
        }

        /*
         * Y que el motor lleve días sin correr TENIENDO reglas encendidas. Sin
         * reglas activas no hay nada que evaluar y no correr es lo correcto —una
         * escuela que todavía no configura el módulo no puede estar en rojo—.
         */
        return $estado['reglas_activas'] > 0
            && ($estado['nunca_corrio'] || $estado['hace_dias'] > self::DIAS_PARA_ALARMAR);
    }

    /**
     * @return array<int, string>
     */
    private function reglasRotas(?CorridaEvaluacion $corrida): array
    {
        return collect($corrida?->errores ?? [])
            ->map(fn ($error) => ($error['regla'] ?? '—').': '.($error['error'] ?? ''))
            ->values()
            ->all();
    }
}
