<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\DisponibilidadDocente;
use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Capturar cuándo puede dar clase un docente, y qué materias sabe dar.
 *
 * ── Dos puertas, un guardado ───────────────────────────────────────────────
 * El docente declara sus horarios desde «Mi expediente» —es quien los sabe— y
 * control escolar puede corregirlos desde su ficha. Es el mismo guardado con
 * distinto quién: separarlos habría llevado a que una puerta valide un traslape
 * que la otra acepta, y a que la disponibilidad de un docente dependa de por
 * dónde se capturó.
 *
 * ── Se guarda el conjunto, no franja por franja ────────────────────────────
 * La pantalla manda la semana completa y aquí se reemplaza entera. Es lo que
 * corresponde al significado del dato: «mi disponibilidad es ésta», no «agrega
 * esta franja». Con altas y bajas sueltas, un error de red deja media semana
 * declarada y nadie sabe cuál mitad.
 *
 * ── Las materias que puede impartir NO las declara él ──────────────────────
 * Ahí decide la escuela: es un juicio sobre su perfil, no un dato que él tenga.
 * Por eso viven en la ficha administrativa y detrás de otro permiso.
 */
class DisponibilidadDocenteController extends Controller
{
    /** Desde la ficha del docente: lo captura control escolar. */
    public function guardarDeDocente(Request $request, Docente $docente): RedirectResponse
    {
        $this->reemplazar($request, $docente->persona_id);

        return back()->with('exito', 'Disponibilidad actualizada.');
    }

    /** Desde «Mi expediente»: lo declara el docente. Sin id en la URL. */
    public function guardarMia(Request $request): RedirectResponse
    {
        $this->reemplazar($request, $this->miPersonaDocente($request));

        return back()->with('exito', 'Tu disponibilidad quedó guardada.');
    }

    /**
     * Las materias que la escuela reconoce que puede impartir.
     *
     * Reemplazo completo por la misma razón que la disponibilidad: es una
     * declaración de perfil, no una bitácora de altas y bajas.
     */
    public function guardarAsignaturas(Request $request, Docente $docente): RedirectResponse
    {
        $datos = $request->validate([
            'asignaturas' => ['present', 'array'],
            'asignaturas.*.asignatura_id' => ['required', 'integer', Rule::exists('asignaturas', 'id')->whereNull('deleted_at')],
            'asignaturas.*.preferencia' => ['required', 'integer', 'in:-1,0,1'],
        ], [], ['asignaturas' => 'materias']);

        $porAsignatura = collect($datos['asignaturas'])
            ->keyBy('asignatura_id')
            ->map(fn (array $a) => ['preferencia' => $a['preferencia']]);

        $docente->asignaturasQuePuedeImpartir()->sync($porAsignatura);

        return back()->with('exito', 'Materias actualizadas.');
    }

    // ── Lo compartido ──────────────────────────────────────────────────────

    /**
     * Reemplaza la disponibilidad de una persona para un ciclo (o la habitual).
     *
     * En una transacción: entre el borrado y la inserción, un docente sin
     * disponibilidad es un docente al que el generador no le pone nada. Que esa
     * ventana no exista importa más aquí que en otras tablas.
     */
    private function reemplazar(Request $request, int $personaId): void
    {
        $datos = $this->validar($request);
        $cicloId = $datos['ciclo_id'] ?? null;

        DB::transaction(function () use ($datos, $personaId, $cicloId) {
            DisponibilidadDocente::query()
                ->where('persona_id', $personaId)
                // `where ciclo_id = null` no compara nunca en SQL: sin esta
                // distinción, guardar la habitual borraría también los ajustes
                // de todos los ciclos.
                ->when($cicloId === null,
                    fn ($q) => $q->whereNull('ciclo_id'),
                    fn ($q) => $q->where('ciclo_id', $cicloId),
                )
                ->delete();

            foreach ($datos['franjas'] as $franja) {
                DisponibilidadDocente::create([
                    'persona_id' => $personaId,
                    'ciclo_id' => $cicloId,
                    'dia_semana' => $franja['dia_semana'],
                    'hora_inicio' => $franja['hora_inicio'],
                    'hora_fin' => $franja['hora_fin'],
                    'modalidad' => $franja['modalidad'],
                    'nota' => $franja['nota'] ?? null,
                ]);
            }
        });
    }

    /**
     * @return array{ciclo_id: ?int, franjas: array<int, array<string, mixed>>}
     */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')->whereNull('deleted_at')],
            'franjas' => ['present', 'array'],
            'franjas.*.dia_semana' => ['required', 'integer', 'between:1,7'],
            'franjas.*.hora_inicio' => ['required', 'date_format:H:i'],
            'franjas.*.hora_fin' => ['required', 'date_format:H:i', 'after:franjas.*.hora_inicio'],
            'franjas.*.modalidad' => ['required', Rule::in(DisponibilidadDocente::MODALIDADES)],
            'franjas.*.nota' => ['nullable', 'string', 'max:200'],
        ], [
            'franjas.*.hora_fin.after' => 'La hora de fin tiene que ser posterior a la de inicio.',
            'franjas.*.hora_inicio.date_format' => 'La hora va en formato HH:MM.',
            'franjas.*.hora_fin.date_format' => 'La hora va en formato HH:MM.',
        ]);

        $this->rechazarTraslapes($datos['franjas']);

        return $datos;
    }

    /**
     * Dos franjas del mismo día no se pueden encimar.
     *
     * No es cosmético: al generar, dos franjas encimadas cuentan dos veces las
     * mismas horas disponibles, y el motor cree que ese docente tiene el doble
     * de hueco del que tiene. El horario sale y no cabe en la realidad.
     *
     * @param  array<int, array<string, mixed>>  $franjas
     */
    private function rechazarTraslapes(array $franjas): void
    {
        $porDia = collect($franjas)->groupBy('dia_semana');

        foreach ($porDia as $dia => $delDia) {
            $ordenadas = $delDia
                ->map(fn (array $f) => [
                    'de' => DisponibilidadDocente::aMinutos($f['hora_inicio']),
                    'a' => DisponibilidadDocente::aMinutos($f['hora_fin']),
                ])
                ->sortBy('de')
                ->values();

            foreach ($ordenadas as $i => $franja) {
                $siguiente = $ordenadas[$i + 1] ?? null;

                AvisoParaElUsuario::si(
                    $siguiente !== null && $siguiente['de'] < $franja['a'],
                    422,
                    'Hay dos horarios encimados el '.self::DIAS[$dia].'. Revísalos: si se enciman, las horas disponibles se cuentan dos veces.',
                );
            }
        }
    }

    private const DIAS = [
        1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves',
        5 => 'viernes', 6 => 'sábado', 7 => 'domingo',
    ];

    /** El docente de la sesión. Sin id en la URL: no hay a quién más editarle. */
    private function miPersonaDocente(Request $request): int
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        $docente = $usuario->persona_id === null
            ? null
            : Docente::query()->find($usuario->persona_id);

        return $docente?->persona_id ?? AvisoParaElUsuario::lanzar(
            403,
            'Tu cuenta todavía no está dada de alta como docente. Pídele a control escolar que la registre.',
        );
    }

    /**
     * Lo que las pantallas necesitan para pintar la captura.
     *
     * Estático porque lo arman dos controladores ajenos —la ficha del docente y
     * su expediente—: si cada uno lo compusiera por su lado, tarde o temprano
     * uno mandaría los ciclos y el otro no.
     *
     * @return array<string, mixed>
     */
    public static function datosPara(int $personaId): array
    {
        return [
            'franjas' => DisponibilidadDocente::query()
                ->where('persona_id', $personaId)
                ->orderBy('dia_semana')->orderBy('hora_inicio')
                ->get()
                ->map(fn (DisponibilidadDocente $d) => [
                    'id' => $d->id,
                    'ciclo_id' => $d->ciclo_id,
                    'dia_semana' => $d->dia_semana,
                    // Sin segundos: es lo que el <input type="time"> entiende.
                    'hora_inicio' => substr((string) $d->hora_inicio, 0, 5),
                    'hora_fin' => substr((string) $d->hora_fin, 0, 5),
                    'modalidad' => $d->modalidad,
                    'nota' => $d->nota,
                ])
                ->values(),
            'ciclos' => Ciclo::query()
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'nombre' => $c->clave ?? $c->nombre]),
        ];
    }
}
