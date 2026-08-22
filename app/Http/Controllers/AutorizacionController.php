<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Autorizacion;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TipoAutorizacion;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que la escuela le pide autorizar a las familias.
 *
 * ── Se emite a los VÍNCULOS de los alumnos elegidos ────────────────────────
 * No a «los padres» en abstracto: se eligen alumnos y se crea una fila por cada
 * familiar vinculado a cada uno. Un alumno con padre y madre registrados
 * produce dos, y la escuela ve cuántas se contestaron. Cuántas respuestas hacen
 * falta no lo decide el sistema —depende del trámite— así que se muestra el
 * conteo y no se inventa un quórum.
 *
 * ── Un alumno sin familiares vinculados se reporta, no se ignora ───────────
 * Es el caso que arruina el trámite: se emite la autorización, la escuela
 * asume que salió a todos, y el día de la excursión resulta que a tres nunca se
 * les pidió nada. Se dicen por su nombre al terminar.
 *
 * ── Reutiliza el buscador de alumnos que ya existe ─────────────────────────
 * `/buscar/alumnos`, con el permiso derivado `dirigir-a-alumnos`. Es el mismo
 * componente con el que se eligen destinatarios de un aviso o de una encuesta;
 * un segundo buscador sería otra forma de contestar la misma pregunta.
 */
class AutorizacionController extends Controller
{
    public function index(Request $peticion): Response
    {
        /*
         * Agrupadas por EMISIÓN y no fila por fila. Una autorización mandada a
         * cuarenta alumnos son ochenta filas, y lo que se mira es «cómo va esa
         * autorización», no cada respuesta suelta.
         */
        $emisiones = Autorizacion::query()
            ->select('titulo', 'tipo_autorizacion_id', 'fecha_limite')
            ->selectRaw('MIN(created_at) as emitida_en')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(concedida = 1) as concedidas')
            ->selectRaw('SUM(concedida = 0) as negadas')
            ->selectRaw('SUM(concedida IS NULL) as pendientes')
            ->groupBy('titulo', 'tipo_autorizacion_id', 'fecha_limite')
            ->orderByDesc('emitida_en')
            ->limit(50)
            ->get();

        $tipos = TipoAutorizacion::query()->get(['id', 'nombre'])->keyBy('id');

        return Inertia::render('Plataforma/Autorizaciones', [
            'emisiones' => $emisiones->map(fn ($e) => [
                'titulo' => $e->titulo,
                'tipo' => $tipos[$e->tipo_autorizacion_id]->nombre ?? null,
                'fecha_limite' => $e->fecha_limite,
                'emitida_en' => $e->emitida_en,
                'total' => (int) $e->total,
                'concedidas' => (int) $e->concedidas,
                'negadas' => (int) $e->negadas,
                'pendientes' => (int) $e->pendientes,
            ]),
            'tipos' => TipoAutorizacion::query()->activos()->get(['id', 'nombre', 'descripcion']),
        ]);
    }

    public function emitir(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'tipo_autorizacion_id' => [
                'required',
                Rule::exists('tipos_autorizacion', 'id')->where('activo', true),
            ],
            'titulo' => ['required', 'string', 'max:180'],
            'detalle' => ['nullable', 'string', 'max:1000'],
            'fecha_limite' => ['nullable', 'date', 'after_or_equal:today'],
            // Sin alumnos no se le pide nada a nadie, y una autorización que no
            // llega a ninguna familia es una fila guardada en una tabla.
            'alumnos' => ['required', 'array', 'min:1'],
            'alumnos.*' => ['integer'],
        ], [
            'fecha_limite.after_or_equal' => 'Una autorización con el plazo ya vencido nace sin poder contestarse.',
        ]);

        $vinculos = TutorAlumno::query()
            ->whereIn('alumno_persona_id', $datos['alumnos'])
            ->get(['id', 'alumno_persona_id']);

        $creadas = 0;

        DB::transaction(function () use ($datos, $vinculos, &$creadas) {
            foreach ($vinculos as $vinculo) {
                Autorizacion::create([
                    'vinculo_familiar_id' => $vinculo->id,
                    'tipo_autorizacion_id' => $datos['tipo_autorizacion_id'],
                    'titulo' => $datos['titulo'],
                    'detalle' => $datos['detalle'] ?? null,
                    'fecha_limite' => $datos['fecha_limite'] ?? null,
                ]);

                $creadas++;
            }
        });

        return back(303)->with(...$this->resultado($creadas, $datos['alumnos'], $vinculos));
    }

    /**
     * El acuse, diciendo por su nombre a quién NO se le pidió.
     *
     * @param  array<int, int>  $alumnos
     * @return array{0: string, 1: string}
     */
    private function resultado(int $creadas, array $alumnos, $vinculos): array
    {
        $conFamilia = $vinculos->pluck('alumno_persona_id')->unique();
        $sinFamilia = collect($alumnos)->diff($conFamilia);

        $mensaje = $creadas === 1
            ? 'Se pidió 1 autorización.'
            : "Se pidieron {$creadas} autorizaciones.";

        if ($sinFamilia->isEmpty()) {
            return ['exito', $mensaje];
        }

        $nombres = Persona::query()
            ->whereIn('id', $sinFamilia)
            ->get()
            ->map(fn ($p) => $p->nombreCompleto())
            ->take(5)
            ->implode(', ');

        $resto = $sinFamilia->count() > 5 ? ' y '.($sinFamilia->count() - 5).' más' : '';

        return ['advertencia', "{$mensaje} A {$sinFamilia->count()} no se les pidió nada porque no tienen familiares vinculados: {$nombres}{$resto}."];
    }

    /**
     * La respuesta del familiar, desde su portal.
     *
     * La autorización se busca DENTRO de las suyas: un id ajeno no encuentra
     * pareja y responde 404. Es la misma salvaguarda que el historial y la
     * credencial, y evita tener que comparar ids a mano.
     */
    public function responder(Request $peticion, Autorizacion $autorizacion): RedirectResponse
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        $esSuya = $usuario->persona_id !== null
            && TutorAlumno::query()
                ->whereKey($autorizacion->vinculo_familiar_id)
                ->where('tutor_persona_id', $usuario->persona_id)
                ->exists();

        abort_unless($esSuya, 404);

        // Vencida no se contesta ni se cambia: nadie des-autoriza la excursión
        // el lunes siguiente.
        abort_unless($autorizacion->admiteRespuesta(), 404);

        $datos = $peticion->validate([
            'concedida' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:500'],
        ]);

        $autorizacion->update([
            'concedida' => $datos['concedida'],
            'comentario' => $datos['comentario'] ?? null,
            'fecha_respuesta' => now(),
        ]);

        return back(303)->with(
            'exito',
            $datos['concedida'] ? 'Autorización concedida.' : 'Autorización negada.',
        );
    }

    /**
     * Las que le tocan a este familiar, para su portal.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deFamiliar(Usuario $usuario): array
    {
        if ($usuario->persona_id === null) {
            return [];
        }

        return Autorizacion::query()
            ->whereIn(
                'vinculo_familiar_id',
                TutorAlumno::query()->where('tutor_persona_id', $usuario->persona_id)->select('id'),
            )
            ->with(['tipo:id,nombre', 'vinculo.alumno:id,nombre,primer_apellido,segundo_apellido'])
            // Lo que falta contestar primero; después lo ya resuelto, lo más
            // reciente arriba.
            ->orderByRaw('concedida IS NOT NULL')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Autorizacion $a) => [
                'id' => $a->id,
                'titulo' => $a->titulo,
                'detalle' => $a->detalle,
                'tipo' => $a->tipo?->nombre,
                'alumno' => $a->vinculo?->alumno?->nombreCompleto(),
                'parentesco' => Parentesco::nombreDe($a->vinculo?->parentesco_id),
                'fecha_limite' => $a->fecha_limite?->toDateString(),
                'vencida' => $a->estaVencida(),
                'concedida' => $a->concedida,
                'comentario' => $a->comentario,
                'fecha_respuesta' => $a->fecha_respuesta?->toDateTimeString(),
                'puede_responder' => $a->admiteRespuesta(),
            ])
            ->all();
    }
}
