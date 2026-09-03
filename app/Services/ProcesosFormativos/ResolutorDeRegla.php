<?php

declare(strict_types=1);

namespace App\Services\ProcesosFormativos;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\ReglaProcesoVersion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use Illuminate\Support\Collection;

/**
 * Qué regla le toca a una matrícula, y en qué versión.
 *
 * ── Gana la MÁS ESPECÍFICA ─────────────────────────────────────────────────
 * Es el molde que este proyecto ya usaba en el cobro —oferta → plan → programa
 * → global—, con dos ejes más. Los pesos de `ReglaProceso::PESOS` son
 * lexicográficos: cada uno vale más que la suma de los de abajo, así que
 * declarar el PLAN gana siempre sobre cualquier combinación de ejes menos
 * específicos. Sin esa propiedad, «campus + modalidad» podría ganarle a «este
 * plan», y nadie podría explicar por qué.
 *
 * ── Y el empate se rompe de forma DETERMINISTA ─────────────────────────────
 * Dos reglas igual de específicas son un error de configuración —la escuela
 * declaró dos veces lo mismo—, pero el sistema no puede quedarse callado ni
 * elegir al azar: gana la de id más alto, es decir la más reciente, y la
 * pantalla enseña cuál se aplicó. Elegir al azar produciría reportes que
 * cambian solos entre dos consultas.
 *
 * ── La VERSIÓN es la vigente a una fecha ───────────────────────────────────
 * Por omisión, hoy. Se pide con fecha para poder reconstruir qué regía cuando
 * se abrió un expediente — aunque el expediente además la congela, porque
 * reconstruirla dependería de que nadie hubiera borrado una versión.
 */
class ResolutorDeRegla
{
    /**
     * La regla aplicable, o null si la escuela no configuró ninguna.
     *
     * Null NO es «no hace falta»: es «nadie ha dicho qué se exige», y quien lo
     * consulte tiene que tratarlo como un impedimento con nombre. Ver
     * {@see ElegibilidadFormativa}.
     */
    public function reglaPara(MatriculaOferta $matricula, TipoProcesoFormativo $tipo): ?ReglaProceso
    {
        return $this->candidatas($matricula, $tipo)->first();
    }

    /**
     * Todas las que alcanzan, ordenadas de la que gana a la que no.
     *
     * Se expone para la pantalla: enseñar por qué ganó una exige poder enseñar
     * contra qué ganó. Sin eso, «no me aplica la regla que yo esperaba» no se
     * puede diagnosticar sin abrir la base.
     *
     * @return Collection<int, ReglaProceso>
     */
    public function candidatas(MatriculaOferta $matricula, TipoProcesoFormativo $tipo): Collection
    {
        $matricula->loadMissing('oferta.programaAcademico');

        return ReglaProceso::query()
            ->activas()
            ->where('tipo_proceso_id', $tipo->id)
            ->with(['campus:id,nombre', 'programaAcademico:id,nombre,nivel_estudios_id', 'plan:id,nombre', 'nivel:id,nombre'])
            ->get()
            ->filter(fn (ReglaProceso $r) => $r->alcanzaA($matricula))
            ->sortByDesc(fn (ReglaProceso $r) => [$r->especificidad(), $r->id])
            ->values();
    }

    /**
     * La versión vigente de una regla a una fecha.
     *
     * Null si la regla existe y todavía no tiene ninguna versión en vigor —una
     * escuela que declaró el alcance y aún no escribió el requisito—. Es un
     * estado real y hay que poder nombrarlo, no dar por buena la primera.
     */
    public function versionVigente(ReglaProceso $regla, ?string $dia = null): ?ReglaProcesoVersion
    {
        return $regla->versiones()
            ->with(['documentos.documento:id,nombre', 'materiasPrevias.planMateria', 'situacionesPermitidas.situacion:id,clave,nombre'])
            ->vigentesAl($dia)
            ->first();
    }

    /**
     * Las dos preguntas juntas, que es como se usan casi siempre.
     *
     * @return array{regla: ReglaProceso|null, version: ReglaProcesoVersion|null}
     */
    public function resolver(MatriculaOferta $matricula, TipoProcesoFormativo $tipo, ?string $dia = null): array
    {
        $regla = $this->reglaPara($matricula, $tipo);

        return [
            'regla' => $regla,
            'version' => $regla === null ? null : $this->versionVigente($regla, $dia),
        ];
    }
}
