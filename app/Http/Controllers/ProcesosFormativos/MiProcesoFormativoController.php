<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Services\ProcesosFormativos\ElegibilidadFormativa;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El portal del alumno: qué proceso le toca, si ya puede y QUÉ LE FALTA.
 *
 * ── La ruta NO lleva id ────────────────────────────────────────────────────
 * La persona sale de la sesión, así que no existe pedir el expediente de otro.
 * Quien estudia dos programas elige entre SUS matrículas, y la elección se
 * busca dentro de esa misma lista: un id ajeno no encuentra pareja y cae a la
 * propia. Es el mismo camino que `/mi-historial` y `/mi-credencial`.
 *
 * ── Y el titular es la MATRÍCULA ───────────────────────────────────────────
 * Quien estudia dos carreras hace dos servicios sociales, con reglas que pueden
 * ser distintas. Por persona, las dos se mezclarían y el porcentaje de créditos
 * saldría de un promedio que no es de ninguna.
 *
 * ── Se enseñan los DOS lados ───────────────────────────────────────────────
 * Lo que falta y lo que ya se cumple. A un alumno al que sólo se le dice lo que
 * le falta no le consta que el sistema haya mirado lo demás — y la primera
 * reacción es ir a ventanilla a preguntar, que es lo que esta pantalla viene a
 * evitar.
 */
class MiProcesoFormativoController extends Controller
{
    public function __construct(private readonly ElegibilidadFormativa $elegibilidad) {}

    public function index(Request $peticion): Response
    {
        $usuario = $peticion->user();

        $matriculas = $this->misMatriculas((int) $usuario->persona_id);

        /*
         * La matrícula pedida se busca DENTRO de las suyas. Un id ajeno no
         * encuentra pareja y cae a la primera propia: no hay 403 que dé pistas
         * ni forma de mirar lo de otro.
         */
        $elegida = $matriculas->firstWhere('id', (int) $peticion->integer('matricula'))
            ?? $matriculas->first();

        return Inertia::render('Procesos/MiProceso', [
            'matriculas' => $matriculas->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'programa' => $m->oferta?->programaAcademico?->nombre,
                'campus' => $m->oferta?->campus?->nombre,
            ])->values(),

            'elegida' => $elegida?->id,

            'procesos' => $elegida === null ? [] : $this->procesosDe($elegida),
        ]);
    }

    /**
     * Un dictamen por cada tipo de proceso que la escuela tiene encendido.
     *
     * Se listan TODOS y no sólo los que le aplican: un alumno que no ve
     * «prácticas profesionales» no sabe si es que no le tocan o si el sistema
     * las perdió. Los que no tienen regla lo dicen con esas palabras.
     *
     * @return array<int, array<string, mixed>>
     */
    private function procesosDe(MatriculaOferta $matricula): array
    {
        return TipoProcesoFormativo::query()
            ->activos()
            ->get()
            ->map(function (TipoProcesoFormativo $tipo) use ($matricula) {
                $dictamen = $this->elegibilidad->para($matricula, $tipo);

                return [
                    'tipo' => $tipo->nombre,
                    'tipo_id' => $tipo->id,
                    'elegible' => $dictamen['elegible'],
                    'obligatorio' => $dictamen['obligatorio'],
                    'impedimentos' => $dictamen['impedimentos'],
                    'cumplidos' => $dictamen['cumplidos'],
                    'avance' => $dictamen['avance'],
                    // Qué regla se aplicó y por qué: sin esto, «no soy elegible»
                    // no se puede discutir con nadie.
                    'regla' => $dictamen['regla'] instanceof ReglaProceso
                        ? ['nombre' => $dictamen['regla']->nombre, 'alcance' => $dictamen['regla']->comoSeLee()]
                        : null,
                    'version' => $dictamen['version']?->version,
                    'horas_requeridas' => $dictamen['version']?->horas_requeridas,
                ];
            })
            ->values()
            ->all();
    }

    /** @return Collection<int, MatriculaOferta> */
    private function misMatriculas(int $personaId): Collection
    {
        return MatriculaOferta::query()
            ->where('persona_id', $personaId)
            ->with([
                'oferta.programaAcademico:id,nombre',
                'oferta.campus:id,nombre',
                'oferta.plan:id,nombre,total_creditos,programa_academico_id',
                'situacion:id,nombre',
            ])
            ->orderBy('id')
            ->get();
    }
}
