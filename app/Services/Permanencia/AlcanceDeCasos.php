<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\CasoPermanencia;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hasta dónde alcanza cada quien en los casos.
 *
 * ── Vive FUERA del controlador, y no por gusto ─────────────────────────────
 * Lo preguntan dos caminos: la pantalla, para listar, y el SERVICIO, para mover.
 * Con la regla sólo en el controlador, `TransicionDeCaso` movería casos que la
 * pantalla no enseña — porque el id viaja por la URL. Es exactamente la lección
 * que dejó `AlcanceDeExpedientes`.
 *
 * ── El campus sale de la COLUMNA del caso, no de la matrícula ──────────────
 * Se copió al abrir a propósito: un alumno que cambia de plantel no puede hacer
 * que un caso cerrado desaparezca del reporte del plantel donde de verdad se
 * atendió. Leerlo por relación haría que el pasado cambiara al mover a alguien.
 */
class AlcanceDeCasos
{
    /** Acota una consulta de casos a lo que este usuario alcanza. */
    public function acotar(Builder $consulta, ?Usuario $usuario): Builder
    {
        $campus = $usuario?->campusVisibles();

        if ($campus === null) {
            return $consulta;
        }

        /*
         * Un caso SIN campus se le enseña a todos. Pasa cuando la oferta no lo
         * tenía al abrirse, y esconderlo de todo el mundo lo convertiría en un
         * caso que nadie atiende. Es el mismo criterio que `Recorte::porColumna`
         * con los aspirantes sin campus.
         */
        return $consulta->where(fn (Builder $q) => $q
            ->whereIn('campus_id', $campus)
            ->orWhereNull('campus_id'));
    }

    public function alcanza(CasoPermanencia $caso, ?Usuario $usuario): bool
    {
        $campus = $usuario?->campusVisibles();

        return $campus === null
            || $caso->campus_id === null
            || in_array($caso->campus_id, $campus, true);
    }

    /**
     * O 404. Nunca 403: un 403 confirmaría que ese caso existe, y con ids
     * consecutivos eso deja enumerar quién tiene seguimiento en los demás
     * planteles.
     */
    public function exigirQueAlcance(CasoPermanencia $caso, ?Usuario $usuario): void
    {
        AvisoParaElUsuario::aMenosQue(
            $this->alcanza($caso, $usuario),
            404,
            'No se encontró el caso.',
        );
    }
}
