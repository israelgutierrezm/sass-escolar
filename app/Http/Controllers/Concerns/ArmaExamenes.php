<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\TipoReactivo;
use App\Models\Lms\Actividad;
use App\Models\Lms\Examen;
use App\Models\Lms\Reactivo;
use App\Models\Lms\Respuesta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Armar un examen: sus reglas y su banco de reactivos.
 *
 * Vive en un trait porque se hace desde dos lados y es el MISMO trabajo: el
 * docente sobre el curso de su grupo, y la escuela sobre la plantilla del plan.
 * Lo único que cambia es quién puede entrar y a qué curso, y eso lo resuelve
 * cada controlador antes de llamar aquí.
 *
 * Duplicar estos métodos habría significado que una corrección a la validación
 * de reactivos —la que impide guardar preguntas que nadie puede calificar— se
 * aplicara en un lado y no en el otro.
 */
trait ArmaExamenes
{
    /**
     * El examen de esta actividad, creándolo con los valores por omisión la
     * primera vez que se abre la pantalla.
     */
    protected function examenDe(Actividad $actividad): Examen
    {
        return Examen::firstOrCreate(['actividad_id' => $actividad->id]);
    }

    /**
     * Todo lo que la pantalla de armado necesita.
     *
     * `rutaBase` viaja como dato porque las dos entradas viven en URLs
     * distintas y la pantalla es la misma: construirla en el frontend a partir
     * de ids obligaría a que la vista supiera desde dónde la abrieron.
     *
     * @return array<string, mixed>
     */
    protected function datosDeArmado(Actividad $actividad, string $rutaBase): array
    {
        $examen = $this->examenDe($actividad);
        $armados = $examen->reactivos()->with('opciones')->get();

        return [
            'ruta_base' => $rutaBase,
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
                'una_por_pagina' => $examen->una_por_pagina,
                'permite_captura' => $examen->permite_captura,
                'intento_que_cuenta' => $examen->intento_que_cuenta,
                'mostrar_resultado' => $examen->mostrar_resultado,
                'se_califica_solo' => $examen->seCalificaSolo(),
                'puntos_totales' => (float) $armados->sum(fn (Reactivo $r) => $examen->puntosDe($r)),
            ],
            'armados' => $armados->map(fn (Reactivo $r) => $this->reactivoParaArmar($r, (float) $examen->puntosDe($r)))->values(),
            'banco' => Reactivo::query()
                ->with('opciones')
                ->where('curso_id', $actividad->curso_id)
                ->whereNotIn('id', $armados->pluck('id'))
                ->orderByDesc('id')
                ->get()
                ->map(fn (Reactivo $r) => $this->reactivoParaArmar($r, (float) $r->puntos))
                ->values(),
            'tiposReactivo' => TipoReactivo::paraSelect(),
        ];
    }

    /** Guarda las reglas de aplicación. */
    protected function guardarReglas(Request $request, Actividad $actividad): void
    {
        $examen = $this->examenDe($actividad);

        $datos = $request->validate([
            'intentos_permitidos' => ['required', 'integer', 'min:1', 'max:10'],
            'minutos_limite' => ['nullable', 'integer', 'min:1', 'max:600'],
            'reactivos_a_presentar' => ['nullable', 'integer', 'min:1', 'max:200'],
            'barajar_reactivos' => ['boolean'],
            'barajar_opciones' => ['boolean'],
            'una_por_pagina' => ['boolean'],
            'permite_captura' => ['boolean'],
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
    }

    /** Alta o edición de un reactivo del banco del curso. */
    protected function guardarReactivoEn(Request $request, int $cursoId, ?Reactivo $reactivo): void
    {
        if ($reactivo !== null && (int) $reactivo->curso_id !== $cursoId) {
            abort(404);
        }

        $datos = $request->validate([
            'tipo' => ['required', Rule::enum(TipoReactivo::class)],
            'enunciado' => ['required', 'string', 'max:4000'],
            'puntos' => ['required', 'numeric', 'min:0.25', 'max:100'],
            'retro_correcta' => ['nullable', 'string', 'max:2000'],
            'retro_incorrecta' => ['nullable', 'string', 'max:2000'],
            'respuesta' => ['nullable', 'array'],
            'opciones' => ['array'],
            'opciones.*.texto' => ['required', 'string', 'max:1000'],
            'opciones.*.correcta' => ['boolean'],
            'opciones.*.pareja' => ['nullable', 'string', 'max:255'],
        ], [], ['enunciado' => 'enunciado', 'opciones.*.texto' => 'texto de la opción']);

        $tipo = TipoReactivo::from($datos['tipo']);
        $opciones = $datos['opciones'] ?? [];

        $this->exigirReactivoUtilizable($tipo, $opciones, $datos['respuesta'] ?? null);

        DB::transaction(function () use ($reactivo, $cursoId, $datos, $tipo, $opciones) {
            $campos = [
                'curso_id' => $cursoId,
                'tipo' => $tipo,
                'enunciado' => $datos['enunciado'],
                'puntos' => $datos['puntos'],
                'retro_correcta' => $datos['retro_correcta'] ?? null,
                'retro_incorrecta' => $datos['retro_incorrecta'] ?? null,
                'respuesta' => $datos['respuesta'] ?? null,
            ];

            $guardado = $reactivo === null
                ? Reactivo::create($campos)
                : tap($reactivo)->update($campos);

            // Se reemplazan enteras: emparejar opción vieja con opción nueva por
            // posición volvería a mezclar respuestas si se reordenan.
            $guardado->opciones()->delete();

            foreach (array_values($opciones) as $i => $opcion) {
                $guardado->opciones()->create([
                    'texto' => $opcion['texto'],
                    'correcta' => (bool) ($opcion['correcta'] ?? false),
                    'pareja' => $opcion['pareja'] ?? null,
                    'orden' => $i + 1,
                ]);
            }
        });
    }

    /**
     * Borra un reactivo del banco.
     *
     * Devuelve el motivo si no se pudo, o null si se borró.
     */
    protected function eliminarReactivoDe(int $cursoId, Reactivo $reactivo): ?string
    {
        abort_unless((int) $reactivo->curso_id === $cursoId, 404);

        // Borrarlo tiraría por cascada las respuestas que ya se dieron: quedaría
        // un intento calificado sobre preguntas que ya no existen y nadie podría
        // atender una inconformidad.
        if (Respuesta::query()->where('reactivo_id', $reactivo->id)->exists()) {
            return 'Ese reactivo ya fue contestado por alguien; solo se puede quitar del examen.';
        }

        $reactivo->delete();

        return null;
    }

    /** Mete o saca reactivos del examen, con el peso que tienen DENTRO de él. */
    protected function armarExamen(Request $request, Actividad $actividad): void
    {
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
    }

    /**
     * Un reactivo que no se puede calificar es peor que no tenerlo: se ve bien
     * en la pantalla y da cero a todo el mundo. Se rechaza al guardarlo, no al
     * aplicarlo.
     *
     * @param  array<int, array<string, mixed>>  $opciones
     */
    protected function exigirReactivoUtilizable(TipoReactivo $tipo, array $opciones, ?array $respuesta): void
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
     * Lo que ve quien ARMA: incluye cuál es la correcta, que es justo lo que
     * `Reactivo::paraResolver()` esconde del alumno.
     *
     * @return array<string, mixed>
     */
    protected function reactivoParaArmar(Reactivo $reactivo, float $puntos): array
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
            'retro_correcta' => $reactivo->retro_correcta,
            'retro_incorrecta' => $reactivo->retro_incorrecta,
            'respuesta' => $reactivo->respuesta,
            'opciones' => $reactivo->opciones->map(fn ($o) => [
                'id' => $o->id,
                'texto' => $o->texto,
                'correcta' => (bool) $o->correcta,
                'pareja' => $o->pareja,
            ])->values(),
        ];
    }
}
