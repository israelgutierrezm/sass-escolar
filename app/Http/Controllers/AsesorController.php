<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\SituacionAsesor;
use App\Models\Identidad\Persona;
use App\Services\AsignadorAsesor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quiénes son los asesores y cuáles están en turno.
 *
 * ── Un hueco de la Fase 1 que nunca se cerró ──────────────────────────────
 * `asesores`, `situaciones_asesor` y `campus_asesor` existen desde la primera
 * fase y NUNCA tuvieron pantalla: la escuela demo tenía cero asesores, así que
 * el pivote `aspirante_asesor` estaba vacío, el embudo no podía acotarse por
 * asignación y la comisión no tenía a quién pagarle. Todo el CRM de captación
 * estaba construido encima de una tabla que nadie podía llenar.
 *
 * ── Ser asesor es una ASIGNACIÓN, no un permiso ───────────────────────────
 * El rol dice qué puede hacer alguien; estar aquí dice que la escuela lo puso a
 * atender prospectos. Es el mismo par que separa al docente de sus materias.
 * Por eso esta pantalla no reparte permisos: se los da `/plataforma/roles`.
 *
 * ── Y se APAGA, no se borra ───────────────────────────────────────────────
 * Un asesor que se va de vacaciones o deja el puesto se pone inactivo: sale del
 * reparto sin perder los prospectos que atendía ni su historial de contactos.
 * Borrarlo dejaría huérfana su cartera y sin autor su bitácora.
 */
class AsesorController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Captacion/Asesores', [
            'asesores' => Asesor::query()
                ->with(['persona:id,nombre,primer_apellido,segundo_apellido,email',
                    'situacion:id,clave,nombre', 'campus:id,nombre'])
                ->get()
                ->map(fn (Asesor $a) => [
                    'persona_id' => $a->persona_id,
                    'nombre' => $a->persona?->nombreCompleto(),
                    'email' => $a->persona?->email,
                    'clave_asesor' => $a->clave_asesor,
                    'activo' => $a->estaActivo(),
                    'campus' => $a->campus->map(fn (Campus $c) => ['id' => $c->id, 'nombre' => $c->nombre])->values(),
                    // Cuánto trae encima: es lo que decide a quién se le carga
                    // el siguiente, y verlo aquí evita repartir a ciegas.
                    'prospectos' => $a->aspirantes()->wherePivot('titular', true)->count(),
                ])
                ->sortByDesc('activo')
                ->values(),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            /*
             * Las DOS reglas del reparto, no una.
             * Se dicen aquí —aunque se configuren en otra pantalla— porque es
             * donde se está pensando en el equipo, y sin saber cómo reparte, la
             * columna de carga no se puede interpretar.
             */
            'reparto' => [
                'quienRegistra' => app(AsignadorAsesor::class)->seLoQuedaQuienRegistra(),
                'modo' => app(AsignadorAsesor::class)->modo(),
            ],
        ]);
    }

    /**
     * Da de alta a un asesor.
     *
     * La persona ya existe en el sistema: un asesor es alguien de la escuela,
     * no un registro nuevo. Se busca por correo o CURP con el mismo buscador
     * que el resto de pantallas.
     */
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'persona_id' => ['required', Rule::exists('personas', 'id')->whereNull('deleted_at')],
            'clave_asesor' => ['nullable', 'string', 'max:50'],
            'campus_ids' => ['array'],
            'campus_ids.*' => ['integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
        ], [], ['persona_id' => 'la persona']);

        if (Asesor::query()->whereKey($datos['persona_id'])->exists()) {
            return back(303)->with('advertencia', 'Esa persona ya es asesora.');
        }

        DB::transaction(function () use ($datos) {
            $asesor = Asesor::create([
                'persona_id' => $datos['persona_id'],
                'clave_asesor' => $datos['clave_asesor'] ?? null,
                // Nace ACTIVO: dar de alta a alguien y que no entre al reparto
                // hasta que además lo enciendas es un paso que se olvida.
                'situacion_id' => $this->situacion('activo'),
            ]);

            $asesor->campus()->sync($datos['campus_ids'] ?? []);
        });

        return back(303)->with('exito', 'Asesor dado de alta.');
    }

    /** Enciende o apaga, y actualiza los campus que atiende. */
    public function update(Request $request, Asesor $asesor): RedirectResponse
    {
        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
            'clave_asesor' => ['nullable', 'string', 'max:50'],
            'campus_ids' => ['array'],
            'campus_ids.*' => ['integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
        ]);

        DB::transaction(function () use ($asesor, $datos, $request) {
            $asesor->update([
                'situacion_id' => $this->situacion($datos['activo'] ? 'activo' : 'inactivo'),
                'clave_asesor' => $datos['clave_asesor'] ?? $asesor->clave_asesor,
            ]);

            // `campus_ids` sólo se toca si vino: el interruptor de la lista
            // manda `activo` a secas y no debe vaciarle los campus de paso.
            if ($request->has('campus_ids')) {
                $asesor->campus()->sync($datos['campus_ids'] ?? []);
            }
        });

        return back(303)->with('exito', $datos['activo'] ? 'Asesor activo.' : 'Asesor inactivo.');
    }

    /**
     * Lo retira del equipo.
     *
     * Sólo si no tiene prospectos: si los tiene, se apaga. Quitarlo dejaría a
     * esos prospectos sin dueño en silencio, que es exactamente lo que este
     * módulo existe para evitar.
     */
    public function destroy(Asesor $asesor): RedirectResponse
    {
        if ($asesor->aspirantes()->exists()) {
            return back(303)->with(
                'error',
                'Tiene prospectos asignados. Ponlo inactivo —sale del reparto y conserva su cartera— '
                .'o reasigna sus prospectos antes de retirarlo.'
            );
        }

        $asesor->delete();

        return back(303)->with('exito', 'Asesor retirado.');
    }

    /** Personas candidatas, para el buscador del alta. */
    public function candidatas(Request $request)
    {
        $termino = trim((string) $request->query('q', ''));

        if (mb_strlen($termino) < 2) {
            return response()->json([]);
        }

        $like = '%'.$termino.'%';

        return response()->json(
            Persona::query()
                // Quien ya es asesor no vuelve a salir: ofrecerlo lleva a un
                // «ya es asesora» que se pudo evitar.
                ->whereNotIn('id', Asesor::query()->select('persona_id'))
                ->where(fn ($q) => $q
                    ->whereRaw("CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido) LIKE ?", [$like])
                    ->orWhere('email', 'like', $like)
                    ->orWhere('curp', 'like', $like))
                ->orderBy('primer_apellido')
                ->limit(15)
                ->get(['id', 'nombre', 'primer_apellido', 'segundo_apellido', 'email'])
                ->map(fn (Persona $p) => [
                    'id' => $p->id,
                    'etiqueta' => $p->nombreCompleto(),
                    'detalle' => $p->email,
                ])
        );
    }

    /** El id de la situación por su CLAVE: los ids cambian al resembrar. */
    private function situacion(string $clave): int
    {
        return (int) SituacionAsesor::query()->where('clave', $clave)->value('id');
    }
}
