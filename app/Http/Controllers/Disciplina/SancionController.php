<?php

declare(strict_types=1);

namespace App\Http\Controllers\Disciplina;

use App\Http\Controllers\Controller;
use App\Models\Disciplina\Incidencia;
use App\Models\Disciplina\Sancion;
use App\Models\Disciplina\TipoSancion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las sanciones, desde control escolar.
 *
 * ── La vigencia la decide el TIPO ──────────────────────────────────────────
 * `desde`/`hasta` sólo se guardan si el tipo `tiene_vigencia`; con un tipo
 * puntual se anulan, y no se dejan pasar los que venga en la petición: cambiar
 * de «suspensión» a «amonestación» no puede conservar unas fechas que ya no
 * significan nada.
 *
 * ── Cita las incidencias que la originaron, si las hay ─────────────────────
 * Se comprueba que las incidencias citadas sean DEL MISMO alumno: sancionar a
 * uno citando la incidencia de otro no tiene sentido y sería un error de captura
 * que nadie querría conservar.
 */
class SancionController extends Controller
{
    public function index(Request $peticion): Response
    {
        $filtros = [
            'busqueda' => trim((string) $peticion->query('busqueda', '')),
            'tipo_id' => $peticion->query('tipo_id'),
        ];

        $sanciones = Sancion::query()
            ->with([
                'matricula:id,matricula,persona_id',
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'tipo:id,nombre,tiene_vigencia',
                'aplica:id,nombre,primer_apellido,segundo_apellido',
                'incidencias:id,descripcion,fecha',
            ])
            ->when($filtros['busqueda'] !== '', function ($q) use ($filtros) {
                $termino = "%{$filtros['busqueda']}%";

                $q->whereHas('matricula', function ($m) use ($termino) {
                    $m->where('matricula', 'like', $termino)
                        ->orWhereHas('persona', fn ($p) => $p
                            ->whereRaw("TRIM(CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)) LIKE ?", [$termino]));
                });
            })
            ->when($filtros['tipo_id'], fn ($q, $v) => $q->where('tipo_sancion_id', $v))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Escolar/Sanciones', [
            'sanciones' => $sanciones->through(fn (Sancion $s) => $this->aFila($s)),
            'filtros' => $filtros,
            'tipos' => TipoSancion::query()->activos()->get(['id', 'nombre', 'tiene_vigencia']),
        ]);
    }

    public function guardar(Request $peticion, ?Sancion $sancion = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'matricula_oferta_id' => ['required', Rule::exists('matricula_oferta', 'id')->whereNull('deleted_at')],
            'tipo_sancion_id' => ['required', Rule::exists('tipos_sancion', 'id')],
            'fecha' => ['required', 'date'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'motivo' => ['required', 'string', 'max:2000'],
            'incidencias' => ['nullable', 'array'],
            'incidencias.*' => ['integer'],
        ], [], [
            'matricula_oferta_id' => 'alumno',
            'tipo_sancion_id' => 'tipo de sanción',
        ]);

        $tipo = TipoSancion::query()->findOrFail($datos['tipo_sancion_id']);

        // La vigencia la manda el TIPO, no la petición: si no tiene vigencia se
        // anula, aunque el formulario haya traído fechas.
        if (! $tipo->tiene_vigencia) {
            $datos['desde'] = null;
            $datos['hasta'] = null;
        }

        // Las incidencias citadas tienen que ser del MISMO alumno.
        $incidencias = $this->incidenciasDelAlumno(
            (array) ($datos['incidencias'] ?? []),
            (int) $datos['matricula_oferta_id'],
        );

        unset($datos['incidencias']);

        DB::connection('tenant')->transaction(function () use (&$sancion, $datos, $incidencias) {
            if ($sancion === null) {
                $datos['aplicada_por'] = Auth::user()?->persona_id;
                $sancion = Sancion::create($datos);
            } else {
                $sancion->update($datos);
            }

            $sancion->incidencias()->sync($incidencias);
        });

        return back(303)->with('exito', 'Sanción guardada.');
    }

    /**
     * Las incidencias de un alumno, para ofrecerlas al armar su sanción.
     *
     * Endpoint aparte porque se piden al elegir la matrícula en el formulario,
     * no vienen en la carga de la pantalla: el alumno se elige buscando.
     */
    public function incidenciasDe(int $matricula): JsonResponse
    {
        $incidencias = Incidencia::query()
            ->where('matricula_oferta_id', $matricula)
            ->with('tipo:id,nombre')
            ->orderByDesc('fecha')
            ->limit(50)
            ->get()
            ->map(fn (Incidencia $i) => [
                'id' => $i->id,
                'tipo' => $i->tipo?->nombre,
                'fecha' => $i->fecha?->format('Y-m-d'),
                'descripcion' => Str::limit($i->descripcion, 80),
            ]);

        return response()->json($incidencias);
    }

    public function eliminar(Sancion $sancion): RedirectResponse
    {
        $sancion->delete();

        return back(303)->with('exito', 'Sanción retirada.');
    }

    /**
     * De los ids que llegaron, los que de verdad son incidencias de ESE alumno.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function incidenciasDelAlumno(array $ids, int $matriculaId): array
    {
        if ($ids === []) {
            return [];
        }

        return Incidencia::query()
            ->where('matricula_oferta_id', $matriculaId)
            ->whereIn('id', array_map('intval', $ids))
            ->pluck('id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function aFila(Sancion $s): array
    {
        return [
            'id' => $s->id,
            'matricula_oferta_id' => $s->matricula_oferta_id,
            'matricula' => $s->matricula?->matricula,
            'alumno' => $s->matricula?->persona?->nombreCompleto(),
            'tipo_id' => $s->tipo_sancion_id,
            'tipo' => $s->tipo?->nombre,
            'tiene_vigencia' => (bool) $s->tipo?->tiene_vigencia,
            'fecha' => $s->fecha?->format('Y-m-d'),
            'desde' => $s->desde?->format('Y-m-d'),
            'hasta' => $s->hasta?->format('Y-m-d'),
            'vigente' => $s->vigente(),
            'motivo' => $s->motivo,
            'aplica' => $s->aplica?->nombreCompleto(),
            'incidencias' => $s->incidencias->map(fn (Incidencia $i) => [
                'id' => $i->id,
                'descripcion' => $i->descripcion,
                'fecha' => $i->fecha?->format('Y-m-d'),
            ])->values(),
        ];
    }
}
