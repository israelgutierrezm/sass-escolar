<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bolsa;

use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Bolsa\Colocacion;
use App\Models\Bolsa\EtapaPostulacion;
use App\Models\Bolsa\Postulacion;
use App\Models\Bolsa\PostulacionBitacora;
use App\Models\Bolsa\Vacante;
use App\Models\Identidad\Persona;
use App\Services\Bolsa\Postulador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las postulaciones de una vacante, desde el lado de vinculación.
 *
 * ── Capturar por ventanilla NO depende del interruptor ────────────────────
 * `bolsa.postulacion_autogestiva` gobierna sólo si el ALUMNO puede postularse
 * solo. Vinculación captura siempre: con el interruptor apagado es el único
 * camino, y con él encendido sigue habiendo postulantes que llegan por
 * teléfono, por correo o en persona.
 */
class PostulacionController extends Controller
{
    public function __construct(private readonly Postulador $postulador) {}

    public function index(Request $peticion, Vacante $vacante): Response
    {
        $vacante->load('empresa:id,razon_social');

        $postulaciones = Postulacion::query()
            ->where('vacante_id', $vacante->id)
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido',
                'etapa:id,nombre,orden',
                'matricula:id,matricula',
            ])
            ->orderByDesc('fecha_postulacion')
            ->get();

        // De una sola consulta: preguntarle a cada renglón si tiene colocación
        // sería una consulta por postulante.
        $colocadas = Colocacion::query()
            ->whereIn('postulacion_id', $postulaciones->pluck('id'))
            ->pluck('postulacion_id');

        return Inertia::render('Bolsa/Postulaciones', [
            'vacante' => [
                'id' => $vacante->id,
                'titulo' => $vacante->titulo,
                'empresa' => $vacante->empresa?->razon_social,
                'vigente' => Vacante::query()->vigentes()->whereKey($vacante->id)->exists(),
            ],
            'postulaciones' => $postulaciones->map(fn (Postulacion $p) => [
                'id' => $p->id,
                'persona' => $p->persona?->nombreCompleto(),
                'matricula' => $p->matricula?->matricula,
                'etapa_id' => $p->etapa_id,
                'etapa' => $p->etapa?->nombre,
                'fecha' => $p->fecha_postulacion?->toDateString(),
                'tiene_cv' => $p->cv_ruta !== null,
                'carta' => $p->carta_presentacion,
                // De dónde llegó: lo que mide si el portal sirve de algo.
                'origen' => $p->esAutogestiva() ? 'Portal' : 'Ventanilla',
                'colocada' => $colocadas->contains($p->id),
            ]),
            // La pantalla necesita saber CUÁL etapa declara la contratación:
            // mover a ésa exige registrar la colocación en el mismo gesto, y sin
            // el dato tendría que adivinarlo por el nombre.
            'etapas' => EtapaPostulacion::query()->activos()->get(['id', 'nombre', 'marca_colocacion']),
        ]);
    }

    /** Vinculación registra a alguien que llegó por otro canal. */
    public function capturar(Request $peticion, Vacante $vacante): RedirectResponse
    {
        $datos = $peticion->validate([
            'persona_id' => ['required', 'integer', Rule::exists('personas', 'id')->whereNull('deleted_at')],
            'matricula_oferta_id' => ['nullable', 'integer'],
            'carta_presentacion' => ['nullable', 'string', 'max:4000'],
        ]);

        $matricula = $this->matriculaDe((int) $datos['persona_id'], $datos['matricula_oferta_id'] ?? null);

        if ($matricula === false) {
            return back(303)->with('error', 'Esa matrícula no es de la persona que estás postulando.');
        }

        try {
            $this->postulador->registrar(
                $vacante,
                (int) $datos['persona_id'],
                $matricula,
                null,
                $datos['carta_presentacion'] ?? null,
                capturadaPor: (int) $peticion->user()->persona_id,
            );
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Postulación registrada.');
    }

    public function mover(Request $peticion, Vacante $vacante, Postulacion $postulacion): RedirectResponse
    {
        // Que la postulación sea DE esa vacante: la ruta lleva los dos ids.
        abort_unless($postulacion->vacante_id === $vacante->id, 404);

        $datos = $peticion->validate([
            'etapa_id' => ['required', Rule::exists('etapas_postulacion', 'id')],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        $this->postulador->mover(
            $postulacion,
            (int) $datos['etapa_id'],
            (int) $peticion->user()->persona_id,
            $datos['nota'] ?? null,
        );

        return back(303)->with('exito', 'Postulación movida.');
    }

    public function bitacora(Vacante $vacante, Postulacion $postulacion): Response
    {
        abort_unless($postulacion->vacante_id === $vacante->id, 404);

        $postulacion->load(['persona:id,nombre,primer_apellido,segundo_apellido']);

        return Inertia::render('Bolsa/PostulacionBitacora', [
            'vacante' => ['id' => $vacante->id, 'titulo' => $vacante->titulo],
            'postulacion' => [
                'id' => $postulacion->id,
                'persona' => $postulacion->persona?->nombreCompleto(),
            ],
            'movimientos' => PostulacionBitacora::query()
                ->where('postulacion_id', $postulacion->id)
                ->with(['origen:id,nombre', 'destino:id,nombre', 'movidaPor:id,nombre,primer_apellido,segundo_apellido'])
                ->orderBy('momento')
                ->get()
                ->map(fn (PostulacionBitacora $b) => [
                    'origen' => $b->origen?->nombre,
                    'destino' => $b->destino?->nombre,
                    // Sin persona = la movió el propio postulante desde su
                    // portal, que hoy sólo pasa en el alta.
                    'quien' => $b->movidaPor?->nombreCompleto() ?? 'El propio postulante',
                    'nota' => $b->nota,
                    'momento' => $b->momento?->toDateTimeString(),
                ]),
        ]);
    }

    /**
     * Las carreras que cursa una persona, para que la ventanilla elija.
     *
     * ── Por qué un endpoint y no un dato del buscador ─────────────────────
     * `/buscar/alumnos` entrega PERSONAS y deduplica a propósito —quien estudia
     * dos carreras no puede salir dos veces en la caja de elegir a alguien—, así
     * que de ahí no sale con cuál se postula. Y no es un caso raro: en la
     * escuela de ejemplo los quince alumnos con matrícula tienen dos o tres, o
     * sea que dejarlo sin preguntar significaría que casi ninguna postulación
     * capturada en ventanilla sabría de qué carrera es, y el indicador de
     * empleabilidad por carrera saldría contando sólo a los del portal.
     */
    public function matriculasDe(Persona $persona): JsonResponse
    {
        return response()->json(
            MatriculaOferta::query()
                ->where('persona_id', $persona->id)
                ->with('oferta:id,carrera_id', 'oferta.carrera:id,nombre')
                ->get()
                ->map(fn (MatriculaOferta $m) => [
                    'id' => $m->id,
                    'matricula' => $m->matricula,
                    'carrera' => $m->oferta?->carrera?->nombre,
                ])
                ->values()
        );
    }

    /**
     * Con qué perfil académico se postula.
     *
     * ── Por qué se resuelve aquí y no lo elige la pantalla ────────────────
     * El buscador de alumnos entrega PERSONAS —deduplica a propósito, para que
     * quien estudia dos carreras no salga dos veces en la caja—, así que la
     * ventanilla no tiene de dónde elegir la matrícula. Cuando la persona tiene
     * una sola, no hay nada que preguntar y se usa; cuando tiene dos, se deja
     * SIN SEÑALAR en vez de adivinar: una postulación colgada de la carrera
     * equivocada torcería los indicadores de empleabilidad y nadie lo notaría.
     * Si viene un id explícito, se comprueba que sea de esa persona.
     *
     * @return int|null|false false = el id que llegó no es de esa persona
     */
    private function matriculaDe(int $personaId, ?int $pedida): int|null|false
    {
        $suyas = MatriculaOferta::query()->where('persona_id', $personaId)->pluck('id');

        if ($pedida !== null) {
            return $suyas->contains($pedida) ? $pedida : false;
        }

        return $suyas->count() === 1 ? (int) $suyas->first() : null;
    }

    public function descargarCv(Vacante $vacante, Postulacion $postulacion): StreamedResponse
    {
        abort_unless($postulacion->vacante_id === $vacante->id, 404);
        abort_if($postulacion->cv_ruta === null, 404);
        abort_unless(Storage::disk('local')->exists($postulacion->cv_ruta), 404);

        return Storage::disk('local')->download($postulacion->cv_ruta);
    }
}
