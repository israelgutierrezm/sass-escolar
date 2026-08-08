<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ModoRedondeo;
use App\Exceptions\AvisoParaElUsuario;
use App\Models\Academico\Carrera;
use App\Models\Academico\PlanEstudio;
use App\Models\Plataforma\Auditoria;
use App\Services\CalificacionesFueraDeEscala;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cómo se califica en cada carrera.
 *
 * ── Se pidió «por carrera» y se guarda por PLAN ────────────────────────────
 * La escala —de cuánto a cuánto y qué es aprobatorio— vive en el plan de
 * estudios desde el principio, y una misma carrera tiene varios: el 2018 podía
 * calificar de 5 a 10 y el 2022 de 0 a 100. Guardar la precisión un nivel más
 * arriba dejaría los límites y los decimales en sitios distintos, y el día que
 * se contradijeran no habría forma de saber cuál manda.
 *
 * Así que la pantalla se ORGANIZA por carrera —que es como se piensa— y escribe
 * en sus planes. Cuando todos los planes de una carrera coinciden, se ve un
 * solo renglón; cuando no, se ven las diferencias, que es justo lo que hay que
 * saber antes de tocar nada.
 */
class ConfiguracionEscolarController extends Controller
{
    public function __construct(private readonly CalificacionesFueraDeEscala $fueraDeEscala) {}

    public function index(Request $request): Response
    {
        /*
         * Qué calificaciones ya capturadas no cumplen la escala de hoy.
         *
         * Cambiar la escala no toca el historial —son actas emitidas—, así que
         * la incoherencia se queda ahí callada: la escuela configura enteros y
         * sigue viendo 8.5 en los kárdex. Esto no arregla nada; lo dice, que es
         * lo que permite decidir.
         */
        $desajustadas = $this->fueraDeEscala->porPlan();

        $carreras = Carrera::query()
            /*
             * El nivel se pide por la RELACIÓN, no consultando un catálogo a
             * mano.
             *
             * Se consultaba `Landlord\NivelEstudio`, y los niveles dejaron de
             * vivir ahí cuando cada escuela pasó a administrar los suyos: el
             * landlord sólo conserva la semilla. La consulta no fallaba —los
             * ids existían, en la tabla equivocada—, así que las carreras de la
             * escuela salían como «Nivel desconocido (#81)» estando bien.
             */
            ->with(['nivelEstudios', 'planes' => fn ($q) => $q->orderBy('nombre')])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Carrera $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'nivel_id' => $c->nivel_estudios_id,
                // Los niveles se enseñan en su progresión —bachillerato antes
                // que doctorado—, que es el `orden` del catálogo. Las que no
                // tienen nivel van al final.
                'nivel_orden' => $c->nivelEstudios?->orden ?? PHP_INT_MAX,
                /*
                 * Un nivel que no está en el catálogo se dice CON su id.
                 *
                 * La carrera SIEMPRE tiene nivel —la columna no admite nulos—,
                 * pero la referencia no lleva llave foránea, así que puede
                 * quedar señalando a uno que se borró. Enseñar el id es lo
                 * único que permite ir a buscarlo.
                 */
                'nivel' => $c->nivelEstudios?->nombre
                    ?? "Nivel desconocido (#{$c->nivel_estudios_id})",
                'planes' => $c->planes->map(fn (PlanEstudio $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'minima' => (float) $p->calificacion_minima,
                    'maxima' => (float) $p->calificacion_maxima,
                    'aprobatoria' => (float) $p->calificacion_minima_aprobatoria,
                    'decimales' => (int) ($p->decimales_calificacion ?? 2),
                    'redondeo' => $p->modoRedondeo()->value,
                    // Lo ya capturado que no cuadra con la escala actual.
                    'desajustadas' => $desajustadas[$p->id] ?? null,
                ])->values(),
            ])
            // Una carrera sin planes no tiene nada que configurar todavía.
            ->filter(fn (array $c) => $c['planes']->isNotEmpty())
            ->values();

        return Inertia::render('Escolar/Configuracion', [
            'carreras' => $carreras,
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    /**
     * Guarda la escala de un plan, o de todos los de su carrera.
     *
     * Lo segundo es lo que hace útil la pantalla: quien decide «esta carrera
     * califica con enteros» lo decide para la carrera, y aplicarlo plan por
     * plan es donde se olvida uno y queda un 2018 calificando distinto que el
     * 2022 sin que nadie lo note hasta un acta.
     */
    public function guardar(Request $request, PlanEstudio $plan): RedirectResponse
    {
        $datos = $request->validate([
            'calificacion_minima' => ['required', 'numeric', 'min:0'],
            'calificacion_maxima' => ['required', 'numeric', 'gt:calificacion_minima'],
            'calificacion_minima_aprobatoria' => ['required', 'numeric'],
            'decimales_calificacion' => ['required', 'integer', 'between:0,3'],
            // Qué se hace con lo que no cabe en esa precisión.
            'redondeo_calificacion' => ['required', Rule::enum(ModoRedondeo::class)],
            // A qué alcanza el cambio: sólo este plan, su carrera o su nivel.
            'aplicar_a' => ['required', 'in:plan,carrera,nivel'],
        ], [
            'calificacion_maxima.gt' => 'La calificación máxima tiene que ser mayor que la mínima.',
            'decimales_calificacion.between' => 'Se puede calificar con 0, 1, 2 o 3 decimales.',
        ]);

        /*
         * La aprobatoria tiene que estar DENTRO de la escala.
         *
         * Fuera de ella, o nadie aprueba nunca o aprueba todo el mundo, y las
         * dos cosas pasan calladas: el número se guarda, las capturas siguen
         * funcionando y el problema sale al cerrar actas.
         */
        AvisoParaElUsuario::si(
            $datos['calificacion_minima_aprobatoria'] < $datos['calificacion_minima']
                || $datos['calificacion_minima_aprobatoria'] > $datos['calificacion_maxima'],
            422,
            'La calificación aprobatoria tiene que estar entre '
                .$datos['calificacion_minima'].' y '.$datos['calificacion_maxima'].'.',
        );

        $escala = collect($datos)->except('aplicar_a')->all();

        $planes = $this->alcanzados($plan, $datos['aplicar_a']);
        $cuantos = PlanEstudio::query()->whereIn('id', $planes)->update($escala);

        return back()->with('exito', match ($datos['aplicar_a']) {
            'nivel' => "Se aplicó a los {$cuantos} planes de ese nivel de estudios.",
            'carrera' => "Se aplicó a los {$cuantos} planes de la carrera.",
            default => 'Escala de calificación actualizada.',
        });
    }

    /**
     * Las calificaciones de un plan que no cumplen su escala, una por una.
     *
     * ── Por qué una lista y no un botón que lo arregle todo ────────────────
     * Son actas asentadas. Un botón que redondea ochenta y cinco calificaciones
     * de golpe cambia promedios, becas y quizá contradice un documento impreso,
     * y lo hace sin que nadie haya visto qué se movía. Aquí se ve cada renglón
     * —de quién es, de qué materia, qué dice y qué quedaría— y se corrige la
     * que se quiera corregir.
     */
    public function calificaciones(Request $request, PlanEstudio $plan): Response
    {
        $plan->load('carrera:id,nombre');

        return Inertia::render('Escolar/CalificacionesFueraDeEscala', [
            'plan' => [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'carrera' => $plan->carrera?->nombre,
                'minima' => (float) $plan->calificacion_minima,
                'maxima' => (float) $plan->calificacion_maxima,
                'decimales' => (int) ($plan->decimales_calificacion ?? 2),
                'como_califica' => $plan->comoSeCalifica(),
                'como_redondea' => $plan->comoSeRedondea(),
            ],
            'filas' => $this->fueraDeEscala->deUnPlan($plan)->map(fn ($f) => [
                'id' => $f->id,
                'matricula' => $f->matricula,
                'alumno' => $f->alumno,
                'materia' => $f->materia,
                'ciclo' => $f->ciclo,
                'calificacion' => (float) $f->calificacion,
                // Lo que quedaría con la escala de hoy: sin esto habría que
                // hacer la cuenta a mano para saber si el cambio es inocuo.
                'sugerida' => (float) $plan->redondear((float) $f->calificacion),
                // Un acta ya asentada no impide corregir, pero tiene que verse.
                'acta' => $f->acta_folio,
            ])->values(),
            'puedeCorregir' => $request->user()->can('capturar-calificaciones'),
        ]);
    }

    /**
     * Corrige UNA calificación del historial.
     *
     * ── Queda registrado, siempre ──────────────────────────────────────────
     * Cambiar una calificación asentada es de las cosas que después alguien
     * pregunta —el alumno, un auditor, la propia escuela— y la respuesta no
     * puede depender de que alguien se acuerde. Va a la bitácora con el valor
     * anterior, el nuevo, quién y desde dónde.
     */
    public function corregirCalificacion(Request $request, int $historial): RedirectResponse
    {
        $fila = DB::table('historial')->where('id', $historial)->whereNull('deleted_at')->first();

        AvisoParaElUsuario::aMenosQue($fila !== null, 404, 'Ese renglón del historial ya no existe.');

        $plan = $this->planDelHistorial($fila);

        AvisoParaElUsuario::aMenosQue(
            $plan !== null,
            422,
            'Ese renglón no cuelga de ningún plan de estudios, así que no hay escala contra la cual corregirlo.',
        );

        $datos = $request->validate([
            'calificacion' => PlanEstudio::reglasPara($plan),
        ]);

        $nueva = (float) $datos['calificacion'];
        $anterior = (float) $fila->calificacion;

        if (abs($nueva - $anterior) < 0.0001) {
            return back()->with('info', 'Esa calificación ya tiene ese valor.');
        }

        DB::transaction(function () use ($fila, $anterior, $nueva, $request) {
            DB::table('historial')->where('id', $fila->id)->update([
                'calificacion' => $nueva,
                'updated_at' => now(),
                'updated_by' => $request->user()->id,
            ]);

            Auditoria::create([
                'auditable_type' => 'historial',
                'auditable_id' => $fila->id,
                'evento' => 'calificacion_corregida',
                // Se guarda el folio del acta: si lo había, es el dato que
                // convierte una corrección en algo que hay que poder explicar.
                'valores_anteriores' => ['calificacion' => $anterior, 'acta_folio' => $fila->acta_folio],
                'valores_nuevos' => ['calificacion' => $nueva],
                'usuario_id' => $request->user()->id,
                'ip' => $request->ip(),
            ]);
        });

        return back()->with('exito', "Calificación corregida de {$anterior} a {$nueva}. Queda registrado quién y cuándo.");
    }

    /** A qué plan pertenece un renglón del historial (llega por la oferta). */
    private function planDelHistorial(object $fila): ?PlanEstudio
    {
        $planId = DB::table('matricula_oferta as mo')
            ->join('oferta as o', 'o.id', '=', 'mo.oferta_id')
            ->where('mo.id', $fila->matricula_oferta_id)
            ->value('o.plan_id');

        return $planId ? PlanEstudio::find($planId) : null;
    }

    /**
     * Qué planes toca el cambio.
     *
     * La escala se guarda SIEMPRE en el plan —es donde han vivido siempre los
     * límites, y separarla de ellos crearía dos fuentes que pueden
     * contradecirse—, pero la decisión rara vez es de un plan suelto: se toma
     * para una carrera («aquí calificamos con enteros») o para un nivel entero
     * («los posgrados van con dos decimales»). Aplicarla plan por plan es donde
     * se olvida uno y queda calificando distinto sin que nadie lo note hasta un
     * acta.
     *
     * El nivel llega por la carrera —`carreras.nivel_estudios_id`—: los planes
     * no lo llevan, así que se pasa por ellas.
     *
     * @return array<int, int>
     */
    private function alcanzados(PlanEstudio $plan, string $alcance): array
    {
        $carreras = match ($alcance) {
            'nivel' => Carrera::query()
                ->where('nivel_estudios_id', $plan->carrera?->nivel_estudios_id)
                ->pluck('id'),
            'carrera' => collect([$plan->carrera_id]),
            default => collect(),
        };

        if ($carreras->isEmpty()) {
            return [$plan->id];
        }

        return PlanEstudio::query()->whereIn('carrera_id', $carreras)->pluck('id')->all();
    }
}
