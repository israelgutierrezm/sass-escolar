<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\ConvertidorAspirante;

/**
 * Cuántos aspirantes ya cumplen todo lo que la escuela exige para matricular.
 *
 * ── Los requisitos NO se recalculan aquí ──────────────────────────────────
 * `ConvertidorAspirante::impedimentos()` es el mismo que va a correr al
 * convertir, y ya respeta los interruptores de la configuración —exigir
 * documentación, exigir el pago—, que a su vez preguntan por el progreso de la
 * solicitud. La tarjeta no sabe nada de esas reglas y ésa es la gracia: la
 * escuela enciende una y el número cambia solo.
 *
 * ── El filtro por ETAPA no es rendimiento: es lo que da sentido al número ──
 * Con los dos interruptores apagados —el valor por omisión— los impedimentos se
 * reducen a «tiene oferta y no está matriculado», y entonces sale «listo» hasta
 * quien apenas contestó el primer mensaje. Contando sólo el final del embudo,
 * la cifra dice lo que se quiere leer: *a los que promoción ya me pasó y
 * todavía no he inscrito*.
 *
 * Se toma la ÚLTIMA etapa por orden y no una clave fija, con la misma línea que
 * usa el convertidor al mover al aspirante: el catálogo lo edita cada escuela y
 * la última es, por definición, el final del recorrido.
 *
 * ── Y hay un tope, con su motivo medido ───────────────────────────────────
 * Con las dos reglas encendidas cada consulta cuesta 23–32 ms porque arrastra
 * el progreso de la solicitud completo. Veinticinco acota el peor caso a menos
 * de un segundo; cuando el tope muerde, el pie lo dice en vez de fingir un
 * total.
 */
class ListosParaConvertir implements TarjetaPanel
{
    private const TOPE = 25;

    public function __construct(private readonly ConvertidorAspirante $convertidor) {}

    public function clave(): string
    {
        return 'listos-para-convertir';
    }

    public function titulo(): string
    {
        return 'Listos para inscribir';
    }

    public function permiso(): ?string
    {
        return 'convertir-aspirante';
    }

    public function tipo(): string
    {
        return 'metrica';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        $ultima = EtapaCrm::query()->orderByDesc('orden')->first(['id', 'nombre']);

        // Sin embudo configurado no existe «el final del embudo».
        if ($ultima === null) {
            return null;
        }

        $campus = $usuario->campusVisibles();

        $candidatos = Aspirante::query()
            ->where('etapa_crm_id', $ultima->id)
            /*
             * Estos dos filtros son literalmente los dos primeros impedimentos
             * del servicio, adelantados a SQL. No es criterio nuevo: el servicio
             * los vuelve a comprobar, y esa consulta redundante es el precio de
             * que la verdad siga viviendo en un solo sitio.
             */
            ->whereNotNull('oferta_interes_id')
            ->whereNotExists(fn ($q) => $q
                ->from((new MatriculaOferta)->getTable())
                ->whereColumn('matricula_oferta.persona_id', 'aspirantes.persona_id')
                ->whereColumn('matricula_oferta.oferta_id', 'aspirantes.oferta_interes_id')
                ->whereNull('matricula_oferta.deleted_at'))
            ->when($campus !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereIn('campus_id', $campus)->orWhereNull('campus_id')
            ))
            ->with(['persona', 'ofertaInteres'])
            // Los que llevan más tiempo esperando entran primero al tope.
            ->orderBy('id')
            ->limit(self::TOPE)
            ->get();

        if ($candidatos->isEmpty()) {
            return null;
        }

        $listos = $candidatos->filter(
            fn (Aspirante $a) => $this->convertidor->impedimentos($a) === []
        )->count();

        /*
         * Cola de trabajo: en cero no se dibuja. «0 listos» no le dice nada
         * accionable a ventanilla, y el día que haya alguien la tarjeta aparece
         * sola. No es como el estado de cuenta propio, donde el cero informa.
         */
        if ($listos === 0) {
            return null;
        }

        return [
            'valor' => $listos,
            'formato' => 'entero',
            'pie' => $candidatos->count() === self::TOPE
                ? 'de los '.self::TOPE.' que llevan más tiempo en «'.$ultima->nombre.'»'
                : 'en «'.$ultima->nombre.'», sin matrícula todavía',
            'alerta' => false,
            'enlace' => '/aspirantes?etapa_crm_id='.$ultima->id,
        ];
    }
}
