<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoReactivo;
use App\Http\Controllers\Concerns\AutorizaMateriaPropia;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Lms\Actividad;
use App\Models\Lms\Examen;
use App\Models\Lms\Intento;
use App\Models\Lms\Reactivo;
use App\Models\Lms\Respuesta;
use App\Services\Lms\AplicadorExamen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Armado y revisión de exámenes, del lado del docente.
 *
 * Tiene pantalla propia y no vive dentro del editor de actividades porque son
 * dos trabajos distintos: poner fecha y ponderación toma un minuto, redactar
 * treinta reactivos toma una tarde. Meterlos en el mismo formulario obligaría a
 * cargar el banco entero cada vez que alguien corrige un título.
 */
class ExamenController extends Controller
{
    use AutorizaMateriaPropia;

    public function __construct(private readonly AplicadorExamen $aplicador) {}

    /** La pantalla de armado: reglas, banco del curso y lo que arma el examen. */
    public function show(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): Response
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        $examen = $this->examenDe($actividad);
        $armados = $examen->reactivos()->with('opciones')->get();
        $enElExamen = $armados->pluck('id');

        return Inertia::render('Docencia/Examen', [
            'materia' => [
                'id' => $asignaturaGrupo->id,
                'nombre' => $asignaturaGrupo->planMateria?->asignatura?->nombre ?? 'Materia',
            ],
            'actividad' => [
                'id' => $actividad->id,
                'titulo' => $actividad->titulo,
                'puntos' => (float) $actividad->puntos,
                'publicada' => (bool) $actividad->publicada,
                'cierra_en' => $actividad->cierra_en?->toDateTimeString(),
            ],
            'examen' => [
                'id' => $examen->id,
                'intentos_permitidos' => $examen->intentos_permitidos,
                'minutos_limite' => $examen->minutos_limite,
                'reactivos_a_presentar' => $examen->reactivos_a_presentar,
                'barajar_reactivos' => $examen->barajar_reactivos,
                'barajar_opciones' => $examen->barajar_opciones,
                'intento_que_cuenta' => $examen->intento_que_cuenta,
                'mostrar_resultado' => $examen->mostrar_resultado,
                'se_califica_solo' => $examen->seCalificaSolo(),
                'puntos_totales' => (float) $armados->sum(fn (Reactivo $r) => $examen->puntosDe($r)),
            ],
            'armados' => $armados->map(fn (Reactivo $r) => $this->paraDocente($r, (float) $examen->puntosDe($r)))->values(),
            'banco' => Reactivo::query()
                ->with('opciones')
                ->where('curso_id', $examen->actividad->curso_id)
                ->whereNotIn('id', $enElExamen)
                ->orderByDesc('id')
                ->get()
                ->map(fn (Reactivo $r) => $this->paraDocente($r, (float) $r->puntos))
                ->values(),
            'tiposReactivo' => TipoReactivo::paraSelect(),
            'intentos' => $this->intentosDelExamen($examen),
        ]);
    }

    /** Guarda las reglas de aplicación. */
    public function actualizar(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        $examen = $this->examenDe($actividad);

        $datos = $request->validate([
            'intentos_permitidos' => ['required', 'integer', 'min:1', 'max:10'],
            'minutos_limite' => ['nullable', 'integer', 'min:1', 'max:600'],
            'reactivos_a_presentar' => ['nullable', 'integer', 'min:1', 'max:200'],
            'barajar_reactivos' => ['boolean'],
            'barajar_opciones' => ['boolean'],
            'intento_que_cuenta' => ['required', Rule::in([Examen::CUENTA_MEJOR, Examen::CUENTA_ULTIMO, Examen::CUENTA_PRIMERO])],
            'mostrar_resultado' => ['required', Rule::in([Examen::RESULTADO_NUNCA, Examen::RESULTADO_AL_ENTREGAR, Examen::RESULTADO_AL_CERRAR])],
        ], [], [
            'intentos_permitidos' => 'intentos permitidos',
            'minutos_limite' => 'límite de minutos',
            'reactivos_a_presentar' => 'reactivos a presentar',
        ]);

        // Pedir más reactivos de los que tiene el examen dejaría exámenes de
        // distinto tamaño según el sorteo: unos alumnos con 20 y otros con 12.
        $armados = $examen->reactivos()->count();

        if (($datos['reactivos_a_presentar'] ?? null) !== null && $datos['reactivos_a_presentar'] > $armados) {
            throw ValidationException::withMessages([
                'reactivos_a_presentar' => "El examen solo tiene {$armados} reactivo(s).",
            ]);
        }

        $examen->update($datos);

        return back()->with('exito', 'Configuración del examen guardada.');
    }

    /** Alta o edición de un reactivo del banco. */
    public function guardarReactivo(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad, ?Reactivo $reactivo = null): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        $cursoId = (int) $actividad->curso_id;

        if ($reactivo !== null && (int) $reactivo->curso_id !== $cursoId) {
            abort(404);
        }

        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoReactivo::class)],
            'enunciado' => ['required', 'string', 'max:4000'],
            'puntos' => ['required', 'numeric', 'min:0.25', 'max:100'],
            'retroalimentacion' => ['nullable', 'string', 'max:2000'],
            'tema' => ['nullable', 'string', 'max:120'],
            'dificultad' => ['nullable', Rule::in(['facil', 'media', 'dificil'])],
            'respuesta' => ['nullable', 'array'],
            'opciones' => ['array'],
            'opciones.*.texto' => ['required', 'string', 'max:1000'],
            'opciones.*.correcta' => ['boolean'],
            'opciones.*.pareja' => ['nullable', 'string', 'max:255'],
        ], [], ['enunciado' => 'enunciado', 'opciones.*.texto' => 'texto de la opción']);

        $tipo = TipoReactivo::from($datos['tipo']);
        $opciones = $datos['opciones'] ?? [];

        $this->exigirReactivoUtilizable($tipo, $opciones, $datos['respuesta'] ?? null);

        DB::transaction(function () use (&$reactivo, $cursoId, $datos, $tipo, $opciones) {
            $campos = [
                'curso_id' => $cursoId,
                'tipo' => $tipo,
                'enunciado' => $datos['enunciado'],
                'puntos' => $datos['puntos'],
                'retroalimentacion' => $datos['retroalimentacion'] ?? null,
                'tema' => $datos['tema'] ?? null,
                'dificultad' => $datos['dificultad'] ?? null,
                'respuesta' => $datos['respuesta'] ?? null,
            ];

            $reactivo = $reactivo === null
                ? Reactivo::create($campos)
                : tap($reactivo)->update($campos);

            // Se reemplazan enteras: emparejar opción vieja con opción nueva por
            // posición volvería a mezclar respuestas si se reordenan.
            $reactivo->opciones()->delete();

            foreach (array_values($opciones) as $i => $opcion) {
                $reactivo->opciones()->create([
                    'texto' => $opcion['texto'],
                    'correcta' => (bool) ($opcion['correcta'] ?? false),
                    'pareja' => $opcion['pareja'] ?? null,
                    'orden' => $i + 1,
                ]);
            }
        });

        return back()->with('exito', 'Reactivo guardado.');
    }

    public function eliminarReactivo(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad, Reactivo $reactivo): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        abort_unless((int) $reactivo->curso_id === (int) $actividad->curso_id, 404);

        // Borrarlo tiraría por cascada las respuestas que ya se dieron: quedaría
        // un intento calificado sobre preguntas que ya no existen y nadie podría
        // atender una inconformidad.
        $yaSeUso = Respuesta::query()->where('reactivo_id', $reactivo->id)->exists();

        if ($yaSeUso) {
            return back()->with('error', 'Ese reactivo ya fue contestado por alguien; solo se puede quitar del examen.');
        }

        $reactivo->delete();

        return back()->with('exito', 'Reactivo eliminado del banco.');
    }

    /** Mete o saca reactivos del examen, con el peso que tienen DENTRO de él. */
    public function armar(Request $request, AsignaturaGrupo $asignaturaGrupo, Actividad $actividad): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);
        $this->exigirDeLaMateria($actividad, $asignaturaGrupo);

        $examen = $this->examenDe($actividad);

        $datos = $request->validate([
            'reactivos' => ['array'],
            'reactivos.*.id' => ['required', 'integer'],
            'reactivos.*.puntos' => ['nullable', 'numeric', 'min:0.25', 'max:100'],
        ]);

        $delCurso = Reactivo::query()
            ->where('curso_id', $actividad->curso_id)
            ->pluck('id')
            ->all();

        $sync = [];

        foreach (array_values($datos['reactivos'] ?? []) as $i => $fila) {
            // Un id de otro curso sería armar el examen con preguntas ajenas.
            if (! in_array((int) $fila['id'], $delCurso, true)) {
                continue;
            }

            $sync[(int) $fila['id']] = ['puntos' => $fila['puntos'] ?? null, 'orden' => $i + 1];
        }

        $examen->reactivos()->sync($sync);

        // Si quedaron menos reactivos que los que se presentan, el sorteo pedía
        // más de los que hay: se apaga en lugar de dejarlo inconsistente.
        if ($examen->reactivos_a_presentar !== null && count($sync) < $examen->reactivos_a_presentar) {
            $examen->update(['reactivos_a_presentar' => null]);
        }

        return back()->with('exito', 'Examen armado.');
    }

    /** El docente pone puntos a un reactivo que la máquina no puede calificar. */
    public function calificarRespuesta(Request $request, AsignaturaGrupo $asignaturaGrupo, Respuesta $respuesta): RedirectResponse
    {
        $this->autorizarMateriaPropia($request, $asignaturaGrupo);

        $suya = $respuesta->intento?->examen?->actividad?->curso?->asignatura_grupo_id === $asignaturaGrupo->id;
        abort_unless($suya, 404);

        $datos = $request->validate([
            'puntos' => ['required', 'numeric', 'min:0'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->aplicador->calificarAMano($respuesta, (float) $datos['puntos'], $datos['comentario'] ?? null);

        return back()->with('exito', 'Respuesta calificada.');
    }

    /**
     * Un reactivo que no se puede calificar es peor que no tenerlo: se ve bien
     * en la pantalla y da cero a todo el mundo. Se rechaza al guardarlo, no al
     * aplicarlo.
     *
     * @param  array<int, array<string, mixed>>  $opciones
     */
    private function exigirReactivoUtilizable(TipoReactivo $tipo, array $opciones, ?array $respuesta): void
    {
        if ($tipo->requiereOpciones() && count($opciones) < 2 && $tipo !== TipoReactivo::Hotspot) {
            throw ValidationException::withMessages(['opciones' => 'Este tipo de reactivo necesita al menos dos opciones.']);
        }

        $conCorrecta = collect($opciones)->contains(fn ($o) => (bool) ($o['correcta'] ?? false));

        $exigenCorrecta = [TipoReactivo::OpcionUnica, TipoReactivo::OpcionMultiple, TipoReactivo::VerdaderoFalso];

        if (in_array($tipo, $exigenCorrecta, true) && ! $conCorrecta) {
            throw ValidationException::withMessages(['opciones' => 'Marca cuál es la respuesta correcta.']);
        }

        if ($tipo === TipoReactivo::OpcionUnica && collect($opciones)->filter(fn ($o) => (bool) ($o['correcta'] ?? false))->count() > 1) {
            throw ValidationException::withMessages(['opciones' => 'Este tipo admite una sola respuesta correcta.']);
        }

        $emparejan = [TipoReactivo::RelacionColumnas, TipoReactivo::Clasificar];

        if (in_array($tipo, $emparejan, true) && collect($opciones)->contains(fn ($o) => blank($o['pareja'] ?? null))) {
            throw ValidationException::withMessages(['opciones' => 'Cada elemento necesita su pareja o su categoría.']);
        }

        $reglas = [
            TipoReactivo::RespuestaCorta->value => fn () => ! empty($respuesta['aceptadas'] ?? []),
            TipoReactivo::Numerica->value => fn () => isset($respuesta['valor']) && is_numeric($respuesta['valor']),
            TipoReactivo::Completar->value => fn () => ! empty($respuesta['huecos'] ?? []),
            TipoReactivo::Hotspot->value => fn () => isset($respuesta['zona']['w'], $respuesta['zona']['h']),
        ];

        if (isset($reglas[$tipo->value]) && ! $reglas[$tipo->value]()) {
            throw ValidationException::withMessages(['respuesta' => 'Falta definir la respuesta correcta de este reactivo.']);
        }
    }

    /**
     * El examen de esta actividad, creándolo con los valores por omisión la
     * primera vez que se abre la pantalla.
     */
    private function examenDe(Actividad $actividad): Examen
    {
        return Examen::firstOrCreate(['actividad_id' => $actividad->id]);
    }

    /**
     * Lo que ve el DOCENTE: incluye cuál es la correcta, que es justo lo que
     * `Reactivo::paraResolver()` esconde del alumno.
     *
     * @return array<string, mixed>
     */
    private function paraDocente(Reactivo $reactivo, float $puntos): array
    {
        return [
            'id' => $reactivo->id,
            'tipo' => $reactivo->tipo->value,
            'tipo_etiqueta' => $reactivo->tipo->etiqueta(),
            'forma' => $reactivo->tipo->formaDeRespuesta(),
            'autocalificable' => $reactivo->tipo->autocalificable(),
            'enunciado' => $reactivo->enunciado,
            'puntos' => $puntos,
            'puntos_banco' => (float) $reactivo->puntos,
            'retroalimentacion' => $reactivo->retroalimentacion,
            'tema' => $reactivo->tema,
            'dificultad' => $reactivo->dificultad,
            'respuesta' => $reactivo->respuesta,
            'opciones' => $reactivo->opciones->map(fn ($o) => [
                'id' => $o->id,
                'texto' => $o->texto,
                'correcta' => (bool) $o->correcta,
                'pareja' => $o->pareja,
            ])->values(),
        ];
    }

    /**
     * Los intentos entregados, con lo que falta por revisar arriba: la pantalla
     * sirve para revisar, no para consultar historial.
     *
     * @return array<int, array<string, mixed>>
     */
    private function intentosDelExamen(Examen $examen): array
    {
        return Intento::query()
            ->with([
                'inscripcion.matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'respuestas.reactivo',
            ])
            ->where('examen_id', $examen->id)
            ->whereNotNull('entregado_en')
            ->orderByDesc('requiere_revision')
            ->orderByDesc('entregado_en')
            ->get()
            ->map(function (Intento $intento) use ($examen) {
                $persona = $intento->inscripcion?->matriculaOferta?->persona;

                return [
                    'id' => $intento->id,
                    'numero' => $intento->numero,
                    'alumno' => trim(implode(' ', array_filter([
                        $persona?->nombre,
                        $persona?->primer_apellido,
                        $persona?->segundo_apellido,
                    ]))) ?: 'Alumno',
                    'entregado_en' => $intento->entregado_en?->toDateTimeString(),
                    'puntos_obtenidos' => (float) $intento->puntos_obtenidos,
                    'puntos_posibles' => (float) $intento->puntos_posibles,
                    'requiere_revision' => (bool) $intento->requiere_revision,
                    // Solo lo que espera al docente: lo autocalificado no se revisa.
                    'pendientes' => $intento->respuestas
                        ->filter(fn (Respuesta $r) => $r->puntos === null)
                        ->map(fn (Respuesta $r) => [
                            'id' => $r->id,
                            'enunciado' => $r->reactivo?->enunciado,
                            'respondio' => $r->valor['v'] ?? null,
                            'tope' => $examen->puntosDe($r->reactivo),
                        ])->values(),
                ];
            })
            ->values()
            ->all();
    }

    private function exigirDeLaMateria(Actividad $actividad, AsignaturaGrupo $asignaturaGrupo): void
    {
        abort_unless($actividad->curso?->asignatura_grupo_id === $asignaturaGrupo->id, 404);
    }
}
