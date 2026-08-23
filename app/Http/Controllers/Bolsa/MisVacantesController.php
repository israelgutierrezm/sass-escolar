<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bolsa;

use App\Http\Controllers\Controller;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Bolsa\Habilidad;
use App\Models\Bolsa\Postulacion;
use App\Models\Bolsa\Vacante;
use App\Models\Identidad\Usuario;
use App\Services\Bolsa\Postulador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El tablero de vacantes del alumno o egresado, y sus postulaciones.
 *
 * ── Ver y postularse son dos preguntas distintas ──────────────────────────
 * El PERMISO decide si esta persona ve el tablero; el AJUSTE
 * `bolsa.postulacion_autogestiva` decide si puede postularse sola. Con el
 * interruptor apagado sigue viendo las vacantes —le sirven: se entera y va a
 * ventanilla— pero el botón no aparece y la dirección responde 404.
 *
 * ── Qué vacantes le tocan ─────────────────────────────────────────────────
 * Las VIGENTES de su carrera, más las que no señalan ninguna —ésas son para
 * todas—. Un egresado que ya no tenga matrícula viva ve las generales, que es
 * más que nada y no le inventa un perfil que ya no tiene.
 */
class MisVacantesController extends Controller
{
    public function __construct(private readonly Postulador $postulador) {}

    public function index(Request $peticion): Response
    {
        $usuario = $peticion->user();
        $matriculas = $this->misMatriculas($usuario);
        $carreras = $matriculas->pluck('oferta.carrera_id')->filter()->unique();

        $vacantes = Vacante::query()
            ->vigentes()
            ->where(fn ($q) => $q
                ->whereDoesntHave('carreras')
                ->when($carreras->isNotEmpty(), fn ($c) => $c->orWhereHas(
                    'carreras',
                    fn ($cc) => $cc->whereIn('carreras.id', $carreras),
                )))
            ->with(['empresa:id,razon_social', 'modalidad:id,nombre', 'jornada:id,nombre', 'habilidades:id,nombre'])
            ->orderByDesc('fecha_publicacion')
            ->get();

        $mias = Postulacion::query()
            ->where('persona_id', $usuario->persona_id)
            ->with(['vacante:id,titulo,empresa_id', 'vacante.empresa:id,razon_social', 'etapa:id,nombre'])
            ->orderByDesc('fecha_postulacion')
            ->get();

        $yaPostulado = $mias->pluck('vacante_id')->all();

        return Inertia::render('Bolsa/MisVacantes', [
            'autogestiva' => $this->postulador->autogestivaEncendida(),
            'vacantes' => $vacantes->map(fn (Vacante $v) => [
                'id' => $v->id,
                'titulo' => $v->titulo,
                'empresa' => $v->empresa?->razon_social,
                'descripcion' => $v->descripcion,
                'modalidad' => $v->modalidad?->nombre,
                'jornada' => $v->jornada?->nombre,
                'ubicacion' => $v->ubicacion,
                'fecha_cierre' => $v->fecha_cierre?->toDateString(),
                'habilidades' => $v->habilidades->map(fn (Habilidad $h) => [
                    'nombre' => $h->nombre,
                    'indispensable' => (bool) $h->pivot->indispensable,
                ]),
                'ya_postulado' => in_array($v->id, $yaPostulado, true),
            ]),
            'postulaciones' => $mias->map(fn (Postulacion $p) => [
                'id' => $p->id,
                'vacante' => $p->vacante?->titulo,
                'empresa' => $p->vacante?->empresa?->razon_social,
                'etapa' => $p->etapa?->nombre,
                'fecha' => $p->fecha_postulacion?->toDateString(),
                'tiene_cv' => $p->cv_ruta !== null,
            ]),
            'matriculas' => $matriculas->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'carrera' => $m->oferta?->carrera?->nombre,
            ])->values(),
        ]);
    }

    public function postularme(Request $peticion, Vacante $vacante): RedirectResponse
    {
        /*
         * 404 y no 403: con el interruptor apagado esta dirección no existe para
         * nadie. Un 403 diría «existe pero no puedes», que abre una conversación
         * que nadie en la escuela va a poder resolver — la respuesta es «ve a
         * ventanilla», y eso lo dice la pantalla.
         */
        abort_unless($this->postulador->autogestivaEncendida(), 404);

        $usuario = $peticion->user();

        $datos = $peticion->validate([
            'matricula_oferta_id' => ['nullable', 'integer'],
            'carta_presentacion' => ['nullable', 'string', 'max:4000'],
            'cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:4096'],
        ], [
            'cv.mimes' => 'El currículum va en PDF o Word.',
        ]);

        /*
         * La matrícula se busca DENTRO de las suyas: quien estudia dos carreras
         * elige con cuál se postula, y un id ajeno no encuentra pareja y cae a
         * null en vez de colgarle a otra persona su postulación.
         */
        $matricula = $this->misMatriculas($usuario)
            ->firstWhere('id', $datos['matricula_oferta_id'] ?? null)?->id;

        $ruta = $peticion->hasFile('cv')
            ? $peticion->file('cv')->store(sprintf('cv/%d', $usuario->persona_id), 'local')
            : null;

        try {
            $this->postulador->registrar(
                $vacante,
                (int) $usuario->persona_id,
                $matricula,
                $ruta,
                $datos['carta_presentacion'] ?? null,
            );
        } catch (RuntimeException $e) {
            // El archivo se descarta: sin postulación a la que colgarlo sólo
            // acumularía currículums que nadie va a volver a mirar.
            $ruta === null || Storage::disk('local')->delete($ruta);

            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Postulación enviada.');
    }

    public function descargarCv(Request $peticion, Postulacion $postulacion): StreamedResponse
    {
        // La suya y sólo la suya: la ruta lleva id.
        abort_unless((int) $postulacion->persona_id === (int) $peticion->user()->persona_id, 404);
        abort_if($postulacion->cv_ruta === null, 404);
        abort_unless(Storage::disk('local')->exists($postulacion->cv_ruta), 404);

        return Storage::disk('local')->download($postulacion->cv_ruta);
    }

    /** Las matrículas de quien tiene la sesión, con su carrera. */
    private function misMatriculas(Usuario $usuario)
    {
        if ($usuario->persona_id === null) {
            return collect();
        }

        return MatriculaOferta::query()
            ->where('persona_id', $usuario->persona_id)
            ->with('oferta:id,carrera_id', 'oferta.carrera:id,nombre')
            ->get();
    }
}
