<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Identidad\Persona;
use App\Models\Nomina\Adscripcion;
use App\Models\Nomina\ExpedienteLaboral;
use App\Models\Nomina\MotivoBajaLaboral;
use App\Models\Nomina\Puesto;
use App\Models\Nomina\SituacionEmpleado;
use App\Models\Nomina\TipoContrato;
use App\Services\Nomina\RegistroLaboral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * El padrón de empleados: quién trabaja aquí, con qué contrato y en qué puesto.
 *
 * ── Se da de alta sobre una PERSONA que ya existe ─────────────────────────
 * Igual que las cuentas de usuario y los docentes: quien entra a trabajar puede
 * haber sido alumno, y crear una persona nueva rompería su historial. Se busca
 * por CURP y, si no está, se captura una persona nueva desde aquí.
 */
class EmpleadoController extends Controller
{
    public function __construct(private readonly RegistroLaboral $registro) {}

    public function index(Request $peticion): Response
    {
        $busqueda = trim((string) $peticion->query('busqueda', ''));
        $campus = $peticion->user()->campusVisibles();

        $expedientes = ExpedienteLaboral::query()
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,curp,foto_url',
                'tipoContrato:id,nombre',
                'situacion:id,nombre,entra_a_nomina',
                'adscripciones.puesto:id,nombre',
                'adscripciones.campus:id,nombre',
            ])
            ->when($busqueda !== '', fn (Builder $q) => $q
                ->where(fn (Builder $d) => $d
                    ->where('numero_empleado', 'like', "%{$busqueda}%")
                    ->orWhereHas('persona', fn (Builder $p) => $p->whereRaw(
                        "TRIM(CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)) LIKE ?",
                        ["%{$busqueda}%"],
                    ))))
            ->when($peticion->filled('situacion_id'), fn (Builder $q) => $q->where('situacion_id', $peticion->integer('situacion_id')))
            ->when($peticion->filled('tipo_contrato_id'), fn (Builder $q) => $q->where('tipo_contrato_id', $peticion->integer('tipo_contrato_id')))
            // Por omisión sólo los que siguen contratados: el padrón contesta
            // «quién trabaja aquí», no «quién trabajó alguna vez».
            ->when($peticion->query('vinculo') !== 'historico', fn (Builder $q) => $q->vigentes())
            /*
             * El alcance por campus mira la ADSCRIPCIÓN, no `persona_rol`: son
             * dos cosas distintas —dónde tiene permisos y dónde tiene su
             * puesto—. Quien todavía no está adscrito a ninguno se ve siempre:
             * esconderlo dejaría un expediente recién dado de alta invisible
             * para quien tiene que adscribirlo.
             */
            ->when($campus !== null, fn (Builder $q) => $q->where(fn (Builder $d) => $d
                ->whereHas('adscripciones', fn (Builder $a) => $a->whereIn('campus_id', $campus))
                ->orWhereDoesntHave('adscripciones')))
            ->orderBy('numero_empleado')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Rh/Empleados', [
            'expedientes' => $expedientes->through(fn (ExpedienteLaboral $e) => $this->renglon($e)),
            'filtros' => [
                'busqueda' => $busqueda,
                'situacion_id' => $peticion->integer('situacion_id') ?: null,
                'tipo_contrato_id' => $peticion->integer('tipo_contrato_id') ?: null,
                'vinculo' => $peticion->query('vinculo'),
            ],
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function ficha(ExpedienteLaboral $expediente): Response
    {
        $expediente->load([
            'persona:id,nombre,primer_apellido,segundo_apellido,curp,rfc,nss,correo_institucional,foto_url',
            'tipoContrato:id,nombre',
            'situacion:id,nombre,entra_a_nomina',
            'motivoBaja:id,nombre',
            'adscripciones.puesto:id,nombre',
            'adscripciones.campus:id,nombre',
        ]);

        return Inertia::render('Rh/Empleado', [
            'expediente' => array_merge($this->renglon($expediente), [
                'persona_id' => $expediente->persona_id,
                'curp' => $expediente->persona?->curp,
                'rfc' => $expediente->persona?->rfc,
                'nss' => $expediente->persona?->nss,
                'correo' => $expediente->persona?->correo_institucional,
                'tipo_contrato_id' => $expediente->tipo_contrato_id,
                'situacion_id' => $expediente->situacion_id,
                'motivo_baja' => $expediente->motivoBaja?->nombre,
                'banco' => $expediente->banco,
                'clabe' => $expediente->clabe,
                'notas' => $expediente->notas,
            ]),
            'adscripciones' => $expediente->adscripciones
                ->sortByDesc('vigente_desde')
                ->values()
                ->map(fn (Adscripcion $a) => [
                    'id' => $a->id,
                    'puesto' => $a->puesto?->nombre,
                    'campus' => $a->campus?->nombre,
                    'desde' => $a->vigente_desde?->toDateString(),
                    'hasta' => $a->vigente_hasta?->toDateString(),
                    'principal' => $a->es_principal,
                    'vigente' => $a->estaVigente(),
                ]),
            'catalogos' => $this->catalogos(),
        ]);
    }

    public function guardar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'persona_id' => ['required', 'integer', Rule::exists('personas', 'id')->whereNull('deleted_at')],
            // Único en toda la escuela. Se captura: una escuela que llega de
            // otro sistema ya trae sus números y generárselos pelearía con los
            // que tiene impresos en los gafetes y en los recibos viejos.
            'numero_empleado' => ['required', 'string', 'max:50', Rule::unique('expedientes_laborales', 'numero_empleado')->whereNull('deleted_at')],
            'tipo_contrato_id' => ['required', Rule::exists('tipos_contrato', 'id')],
            'situacion_id' => ['required', Rule::exists('situaciones_empleado', 'id')],
            'fecha_ingreso' => ['required', 'date'],
            'banco' => ['nullable', 'string', 'max:60'],
            'clabe' => ['nullable', 'string', 'size:18'],
            'nss' => ['nullable', 'string', 'max:15'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ], [
            'clabe.size' => 'La CLABE interbancaria son 18 dígitos.',
        ]);

        $nss = $datos['nss'] ?? null;
        unset($datos['nss']);

        $expediente = ExpedienteLaboral::create($datos);

        // El NSS es de la PERSONA, no del expediente: quien es recontratado no
        // vuelve a capturarlo. Sólo se escribe si viene algo.
        if ($nss !== null && $nss !== '') {
            Persona::whereKey($datos['persona_id'])->update(['nss' => $nss]);
        }

        return redirect("/rh/empleados/{$expediente->id}")->with('exito', 'Expediente laboral creado.');
    }

    public function actualizar(Request $peticion, ExpedienteLaboral $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'numero_empleado' => [
                'required', 'string', 'max:50',
                Rule::unique('expedientes_laborales', 'numero_empleado')->ignore($expediente->id)->whereNull('deleted_at'),
            ],
            'tipo_contrato_id' => ['required', Rule::exists('tipos_contrato', 'id')],
            'situacion_id' => ['required', Rule::exists('situaciones_empleado', 'id')],
            'fecha_ingreso' => ['required', 'date'],
            'banco' => ['nullable', 'string', 'max:60'],
            'clabe' => ['nullable', 'string', 'size:18'],
            'nss' => ['nullable', 'string', 'max:15'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ], [
            'clabe.size' => 'La CLABE interbancaria son 18 dígitos.',
        ]);

        $nss = $datos['nss'] ?? null;
        unset($datos['nss']);

        // La fecha de baja NO se edita aquí: se pone al dar de baja, con su
        // motivo, y se quita al reactivar. Dejarla suelta en este formulario
        // permitiría cerrar un vínculo sin decir por qué.
        $expediente->update($datos);

        Persona::whereKey($expediente->persona_id)->update(['nss' => $nss === '' ? null : $nss]);

        return back(303)->with('exito', 'Expediente actualizado.');
    }

    public function darDeBaja(Request $peticion, ExpedienteLaboral $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'fecha_baja' => ['required', 'date'],
            'motivo_baja_id' => ['required', Rule::exists('motivos_baja_laboral', 'id')],
        ]);

        try {
            $this->registro->darDeBaja($expediente, $datos['fecha_baja'], (int) $datos['motivo_baja_id']);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Expediente dado de baja.');
    }

    public function reactivar(ExpedienteLaboral $expediente): RedirectResponse
    {
        try {
            $this->registro->reactivar($expediente);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Se deshizo la baja. Vuelve a abrirle su adscripción.');
    }

    public function adscribir(Request $peticion, ExpedienteLaboral $expediente): RedirectResponse
    {
        $datos = $peticion->validate([
            'puesto_id' => ['required', Rule::exists('puestos', 'id')],
            'campus_id' => ['required', Rule::exists('campus', 'id')],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date'],
            'es_principal' => ['boolean'],
        ]);

        try {
            $this->registro->adscribir(
                $expediente,
                (int) $datos['puesto_id'],
                (int) $datos['campus_id'],
                $datos['vigente_desde'],
                $datos['vigente_hasta'] ?? null,
                (bool) ($datos['es_principal'] ?? false),
            );
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Adscripción registrada.');
    }

    public function cerrarAdscripcion(Request $peticion, ExpedienteLaboral $expediente, Adscripcion $adscripcion): RedirectResponse
    {
        abort_unless($adscripcion->expediente_laboral_id === $expediente->id, 404);

        $datos = $peticion->validate(['vigente_hasta' => ['required', 'date']]);

        try {
            $this->registro->cerrarAdscripcion($adscripcion, $datos['vigente_hasta']);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        return back(303)->with('exito', 'Adscripción cerrada.');
    }

    /** Personas sin expediente vigente, para el alta. */
    public function candidatos(Request $peticion)
    {
        $texto = trim((string) $peticion->query('q', ''));

        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }

        return response()->json(
            Persona::query()
                ->whereRaw(
                    "TRIM(CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)) LIKE ?",
                    ["%{$texto}%"],
                )
                ->orWhere('curp', 'like', "%{$texto}%")
                ->orderBy('primer_apellido')
                ->limit(20)
                ->get(['id', 'nombre', 'primer_apellido', 'segundo_apellido', 'curp'])
                ->map(fn (Persona $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombreCompleto(),
                    'curp' => $p->curp,
                    // Se dice si ya tiene uno vigente en vez de esconderla: la
                    // doble plaza es legítima, pero quien captura tiene que
                    // saber que no está dando de alta a alguien por segunda vez
                    // sin querer.
                    'carrera' => ExpedienteLaboral::query()->vigentes()->where('persona_id', $p->id)->exists()
                        ? 'Ya tiene un expediente vigente'
                        : null,
                ])
        );
    }

    /** @return array<string, mixed> */
    private function renglon(ExpedienteLaboral $expediente): array
    {
        $actual = $expediente->adscripcionActual();

        return [
            'id' => $expediente->id,
            'persona' => $expediente->persona?->nombreCompleto(),
            'numero_empleado' => $expediente->numero_empleado,
            'tipo_contrato' => $expediente->tipoContrato?->nombre,
            'situacion' => $expediente->situacion?->nombre,
            // Lo que decide si se le paga. Va al renglón porque «activo» y
            // «licencia sin goce» se leen igual de bien y significan lo opuesto
            // el día de la nómina.
            'en_nomina' => (bool) $expediente->situacion?->entra_a_nomina,
            'puesto' => $actual?->puesto?->nombre,
            'campus' => $actual?->campus?->nombre,
            'fecha_ingreso' => $expediente->fecha_ingreso?->toDateString(),
            'fecha_baja' => $expediente->fecha_baja?->toDateString(),
            'vigente' => $expediente->sigueContratado(),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogos(): array
    {
        return [
            'tipos_contrato' => TipoContrato::query()->activos()->get(['id', 'nombre']),
            'situaciones' => SituacionEmpleado::query()->activos()->get(['id', 'nombre', 'entra_a_nomina']),
            'motivos_baja' => MotivoBajaLaboral::query()->activos()->get(['id', 'nombre']),
            'puestos' => Puesto::query()->activos()->get(['id', 'nombre']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
