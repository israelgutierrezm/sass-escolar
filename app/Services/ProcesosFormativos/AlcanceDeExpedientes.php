<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\OrganizacionContacto;
use App\Support\CatalogoPermisos;
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
 * ── El SUPERVISOR externo NO se acota por campus ──────────────────────────
 * Su faceta se resuelve ANTES de mirar el campus, y no por gusto: un supervisor
 * no tiene `persona_rol.campus_id`, así que `campusVisibles()` le devolvería
 * NULL —«todos»— y vería la escuela entera. Se acota por otra cosa: los
 * expedientes cuyo contacto de supervisión es ÉL y cuyo acceso está VIGENTE.
 * Un acceso revocado o vencido deja el alcance en NADA, que es el lado seguro:
 * el mismo criterio que este servicio ya aplica cuando el campus no calza.
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
        if ($this->esSupervisor($quien)) {
            return $this->acotarASupervisor($consulta, $quien);
        }

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
        if ($this->esSupervisor($quien)) {
            $expediente->loadMissing('supervisor');
            $supervisor = $expediente->supervisor;

            return $supervisor !== null
                && (int) $supervisor->persona_id === (int) $quien->persona_id
                && $supervisor->accesoVigente();
        }

        $campus = $quien?->campusVisibles();

        if ($campus === null) {
            return true;
        }

        $expediente->loadMissing('matricula.oferta:id,campus_id');

        return in_array((int) $expediente->matricula?->oferta?->campus_id, array_map('intval', $campus), true);
    }

    /**
     * ¿El rol activo es la faceta de supervisor externo?
     *
     * Se pregunta por la FACETA y no por un permiso: un permiso lo puede tener
     * también un administrativo (para validar horas del mostrador), y aquí lo
     * que decide el ALCANCE es el oficio con el que se está mirando la
     * plataforma. Es la misma línea que separa al docente del control escolar.
     */
    private function esSupervisor(?Usuario $quien): bool
    {
        return $quien?->rolActivo?->faceta()?->name === CatalogoPermisos::SUPERVISOR;
    }

    /** @param  Builder<ExpedienteProceso>  $consulta */
    private function acotarASupervisor(Builder $consulta, Usuario $quien): Builder
    {
        // Sin persona detrás no supervisa a nadie: alcance vacío, nunca «todos».
        if ($quien->persona_id === null) {
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta->whereHas('supervisor', function (Builder $c) use ($quien): void {
            /** @var Builder<OrganizacionContacto> $c */
            $c->where('persona_id', $quien->persona_id)->conAccesoVigente();
        });
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
