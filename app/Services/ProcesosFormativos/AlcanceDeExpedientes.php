<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién alcanza qué expediente.
 *
 * ── Vive fuera del controlador porque lo preguntan DOS caminos ────────────
 * La pantalla —para listar— y {@see TransicionDeExpediente} —para mover—. Con
 * la regla escrita sólo en el controlador, el servicio movería expedientes que
 * la pantalla no enseña: el id viaja por la URL y filtrar la lista nunca ha
 * sido una defensa. Es la misma lección que `AcotaPorCampus` dejó escrita en su
 * propio docblock, aplicada a un servicio.
 *
 * ── El alcance del expediente sale de su MATRÍCULA ────────────────────────
 * `expedientes_proceso` no tiene `campus_id` y no debe tenerlo: el campus es de
 * la oferta, y copiarlo aquí crearía un segundo dato que se separaría el día
 * que alguien cambie de plantel. Se llega por `matricula.oferta.campus_id`,
 * que es el mismo camino que usan la cartera y el historial.
 *
 * ── `campusVisibles()` devuelve NULL con alcance global ───────────────────
 * Null NO es «ninguno», es «todos». Confundirlos deja a dirección general sin
 * ver nada — o, al revés, deja al coordinador de un plantel viendo la escuela
 * entera. Por eso las dos funciones salen temprano cuando es null.
 */
class AlcanceDeExpedientes
{
    /** @param  Builder<ExpedienteProceso>  $consulta */
    public function acotar(Builder $consulta, ?Usuario $quien): Builder
    {
        $campus = $quien?->campusVisibles();

        if ($campus === null) {
            return $consulta;
        }

        return $consulta->whereHas(
            'matricula.oferta',
            fn (Builder $o) => $o->whereIn('campus_id', $campus),
        );
    }

    public function alcanza(ExpedienteProceso $expediente, ?Usuario $quien): bool
    {
        $campus = $quien?->campusVisibles();

        if ($campus === null) {
            return true;
        }

        $expediente->loadMissing('matricula.oferta:id,campus_id');

        return in_array((int) $expediente->matricula?->oferta?->campus_id, array_map('intval', $campus), true);
    }

    /**
     * @throws AvisoParaElUsuario 403 con su razón escrita
     */
    public function exigirQueAlcance(ExpedienteProceso $expediente, ?Usuario $quien): void
    {
        AvisoParaElUsuario::aMenosQue(
            $this->alcanza($expediente, $quien),
            403,
            'Ese expediente es de un campus que tu rol no alcanza.',
        );
    }
}
