<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Formularios\Formulario;
use App\Models\Promocion\Comision;
use App\Models\Promocion\FormularioPublico;
use App\Models\Promocion\OrigenAspirante;
use App\Models\Promocion\ReglaComision;
use App\Models\Promocion\SeguimientoAspirante;
use App\Models\Promocion\TipoSeguimiento;
use App\Services\EmbudoAdmision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * El CRM de promoción: el embudo, el seguimiento y las comisiones.
 *
 * Alcance en dos capas, la misma regla que ya gobierna al docente: el PERMISO
 * dice qué puede hacer el rol (`ver-mis-prospectos` para el promotor,
 * `gestionar-promocion` para quien coordina); la ASIGNACIÓN en
 * `aspirante_asesor` dice sobre quién. Un promotor con el permiso no ve los
 * prospectos de otro.
 */
class PromocionController extends Controller
{
    public function __construct(private readonly EmbudoAdmision $embudo) {}

    /** El tablero: embudo por etapa, de dónde llegan y qué toca contactar hoy. */
    public function index(Request $request): Response
    {
        $usuario = $request->user();

        return Inertia::render('Promocion/Tablero', [
            'etapas' => $this->embudo->porEtapa($usuario),
            'origenes' => $this->embudo->porOrigen($usuario),
            'pendientes' => $this->embudo->pendientesDeContacto($usuario),
            'total' => $this->embudo->acotar(Aspirante::query(), $usuario)->count(),
            'esCoordinador' => $usuario->can('gestionar-promocion'),
        ]);
    }

    /** Prospectos de una etapa concreta, con su último contacto. */
    public function etapa(Request $request, EtapaCrm $etapa): Response
    {
        // La etapa ya acota la lista, pero dentro de una etapa llena —«contacto
        // inicial» acumula cientos— lo que se busca es un nombre concreto, o de
        // qué campaña salieron, o qué trae asignado tal promotor.
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'origen_id' => $request->query('origen_id'),
            'oferta_id' => $request->query('oferta_id'),
            'promotor_id' => $request->query('promotor_id'),
        ];

        $aspirantes = $this->embudo
            ->acotar(Aspirante::query(), $request->user())
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido,celular,email,foto_url',
                'ofertaInteres.carrera:id,nombre',
                'origenAspirante:id,nombre',
                'asesores.persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->where('etapa_crm_id', $etapa->id)
            ->when($filtros['busqueda'] !== '', function ($query) use ($filtros) {
                $termino = "%{$filtros['busqueda']}%";

                $query->whereHas('persona', fn ($p) => $p
                    ->whereRaw("concat_ws(' ', nombre, primer_apellido, segundo_apellido) like ?", [$termino])
                    ->orWhere('celular', 'like', $termino)
                    ->orWhere('email', 'like', $termino));
            })
            ->when($filtros['origen_id'], fn ($q, $v) => $q->where('origen_id', $v))
            ->when($filtros['oferta_id'], fn ($q, $v) => $q->where('oferta_interes_id', $v))
            ->when($filtros['promotor_id'], fn ($q, $v) => $q->whereHas(
                'asesores',
                fn ($a) => $a->where('asesores.persona_id', $v),
            ))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Aspirante $a) => [
                'id' => $a->id,
                'nombre' => $a->persona?->nombreCompleto(),
                'telefono' => $a->persona?->celular,
                'email' => $a->persona?->email,
                'carrera' => $a->ofertaInteres?->carrera?->nombre,
                'origen' => $a->origenAspirante?->nombre,
                'foto' => $a->persona?->urlFoto(),
                'titular' => $a->asesores->first(fn ($x) => (bool) $x->pivot->titular)?->persona?->nombreCompleto(),
                'ultimo_contacto' => $a->seguimientos()->value('momento'),
            ]);

        return Inertia::render('Promocion/Etapa', [
            'etapa' => ['id' => $etapa->id, 'nombre' => $etapa->nombre, 'clave' => $etapa->clave],
            'aspirantes' => $aspirantes,
            'etapas' => EtapaCrm::orderBy('orden')->get(['id', 'nombre']),
            'filtros' => $filtros,
            'origenes' => OrigenAspirante::query()->activos()->orderBy('nombre')->get(['id', 'nombre']),
            'ofertas' => Oferta::query()->with('carrera:id,nombre')->get()
                ->map(fn (Oferta $o) => ['id' => $o->id, 'nombre' => $o->carrera?->nombre ?? '—'])
                ->sortBy('nombre')->values(),
            'promotores' => Asesor::query()->with('persona:id,nombre,primer_apellido,segundo_apellido')->get()
                ->map(fn (Asesor $a) => ['id' => $a->persona_id, 'nombre' => $a->persona?->nombreCompleto() ?? '—'])
                ->sortBy('nombre')->values(),
        ]);
    }

    /** Registra un contacto y, si se pide, mueve al prospecto de etapa. */
    public function seguir(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $this->autorizarProspecto($request, $aspirante);

        $datos = $request->validate([
            'tipo_id' => ['nullable', Rule::exists('tipos_seguimiento', 'id')],
            'nota' => ['required', 'string', 'min:3', 'max:2000'],
            'proximo_contacto' => ['nullable', 'date'],
            'etapa_destino_id' => ['nullable', Rule::exists('etapas_crm', 'id')],
        ]);

        try {
            $this->embudo->registrarSeguimiento($aspirante, $datos, $request->user()?->persona_id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', 'Seguimiento registrado.');
    }

    /**
     * Asigna un promotor. Solo uno puede ser TITULAR: es quien responde por el
     * prospecto y quien devengará la comisión si se inscribe.
     */
    public function asignarPromotor(Request $request, Aspirante $aspirante): RedirectResponse
    {
        $datos = $request->validate([
            'persona_id' => ['required', Rule::exists('asesores', 'persona_id')],
            'titular' => ['boolean'],
        ]);

        DB::transaction(function () use ($aspirante, $datos) {
            // Un solo titular: dos comisiones por el mismo alumno serían pagar
            // dos veces por un resultado.
            if ($datos['titular'] ?? false) {
                DB::table('aspirante_asesor')
                    ->where('aspirante_id', $aspirante->id)
                    ->update(['titular' => false]);
            }

            $aspirante->asesores()->syncWithoutDetaching([
                $datos['persona_id'] => ['titular' => $datos['titular'] ?? false],
            ]);
        });

        return back()->with('exito', 'Promotor asignado.');
    }

    public function retirarPromotor(Aspirante $aspirante, int $personaId): RedirectResponse
    {
        $aspirante->asesores()->detach($personaId);

        return back()->with('exito', 'Promotor retirado.');
    }

    /** Comisiones devengadas, por promotor. */
    public function comisiones(Request $request): Response
    {
        $estatus = (string) $request->query('estatus', '');

        $consulta = Comision::query()
            ->with([
                'asesor.persona:id,nombre,primer_apellido,segundo_apellido',
                'matriculaOferta:id,matricula',
                'aspirante:id,persona_id',
                'regla:id,nombre',
            ])
            ->when($estatus !== '', fn ($q) => $q->where('estatus', $estatus))
            ->orderByDesc('devengada_en');

        // El promotor ve las SUYAS; quien coordina las de todos. Las comisiones
        // ajenas son información de nómina y no le tocan.
        if (! $request->user()->can('gestionar-comisiones')) {
            $consulta->where('persona_id', $request->user()->persona_id);
        }

        $totales = (clone $consulta)
            ->reorder()
            ->selectRaw('estatus, count(*) as total, coalesce(sum(monto), 0) as monto')
            ->groupBy('estatus')
            ->get();

        return Inertia::render('Promocion/Comisiones', [
            'comisiones' => $consulta->paginate(30)->withQueryString()->through(fn (Comision $c) => [
                'id' => $c->id,
                'promotor' => $c->asesor?->persona?->nombreCompleto(),
                'matricula' => $c->matriculaOferta?->matricula,
                'aspirante_id' => $c->aspirante_id,
                'regla' => $c->regla?->nombre,
                'monto' => (float) $c->monto,
                'estatus' => $c->estatus,
                'devengada_en' => $c->devengada_en?->toDateTimeString(),
                'pagada_en' => $c->pagada_en?->toDateTimeString(),
                'motivo_cancelacion' => $c->motivo_cancelacion,
            ]),
            'filtros' => ['estatus' => $estatus],
            'totales' => $totales->map(fn ($t) => [
                'estatus' => $t->estatus,
                'total' => (int) $t->total,
                'monto' => round((float) $t->monto, 2),
            ]),
            'puedeGestionar' => $request->user()->can('gestionar-comisiones'),
            'puedeConfigurar' => $request->user()->can('configurar-comisiones'),
            // Sin regla vigente nadie devenga nada. La pantalla lo dice en vez
            // de dejar que la escuela lo descubra cuando un promotor reclame.
            'reglas' => ReglaComision::query()->orderByDesc('vigente_desde')->get()
                ->map(fn (ReglaComision $r) => [
                    'id' => $r->id,
                    'nombre' => $r->nombre,
                    'aplica_a_tipo' => $r->aplica_a_tipo,
                    'destinatario' => $r->nombreDelDestinatario(),
                    'modo' => $r->modo,
                    'valor' => (float) $r->valor,
                    'concepto' => $r->concepto?->nombre,
                    'vigente_desde' => $r->vigente_desde?->toDateString(),
                    'vigente_hasta' => $r->vigente_hasta?->toDateString(),
                    'activo' => $r->activo,
                    'devengadas' => Comision::where('regla_id', $r->id)->count(),
                ]),
            'conceptos' => ConceptoPago::orderBy('nombre')->get(['id', 'nombre']),
            'destinos' => [
                'carrera' => Carrera::orderBy('nombre')->get(['id', 'nombre']),
                'oferta' => Oferta::with('carrera:id,nombre', 'campus:id,nombre')->get()
                    ->map(fn ($o) => ['id' => $o->id, 'nombre' => ($o->carrera?->nombre ?? '—').' · '.($o->campus?->nombre ?? '—')]),
            ],
        ]);
    }

    /**
     * Marca comisiones como pagadas. No se borran ni se editan los montos: una
     * comisión es lo que se devengó ese día, y la nómina de promoción tiene que
     * poder reconstruirse después.
     */
    public function pagarComisiones(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [Rule::exists('comisiones', 'id')],
        ]);

        $pagadas = Comision::query()
            ->whereIn('id', $datos['ids'])
            ->porPagar()
            ->update(['estatus' => Comision::ESTATUS_PAGADA, 'pagada_en' => now()]);

        return back()->with(
            'exito',
            $pagadas === 1 ? 'Se marcó 1 comisión como pagada.' : "Se marcaron {$pagadas} comisiones como pagadas."
        );
    }

    public function cancelarComision(Request $request, Comision $comision): RedirectResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        if ($comision->estatus === Comision::ESTATUS_PAGADA) {
            return back()->with('error', 'Esa comisión ya se pagó. Cancelarla no devuelve el dinero: regístralo como ajuste.');
        }

        $comision->update([
            'estatus' => Comision::ESTATUS_CANCELADA,
            'motivo_cancelacion' => $datos['motivo'],
        ]);

        return back()->with('exito', 'Comisión cancelada.');
    }

    /**
     * Alta de una regla de comisión. Sin al menos una vigente, nadie devenga
     * nada: la pantalla lo dice en vez de dejar que la escuela descubra que sus
     * promotores no cobran.
     */
    public function guardarRegla(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'aplica_a_tipo' => ['required', Rule::in([
                ReglaComision::APLICA_GLOBAL,
                ReglaComision::APLICA_CARRERA,
                ReglaComision::APLICA_OFERTA,
            ])],
            'aplica_a_id' => ['nullable', 'integer'],
            'modo' => ['required', Rule::in([ReglaComision::MODO_MONTO_FIJO, ReglaComision::MODO_PORCENTAJE])],
            'valor' => ['required', 'numeric', 'min:0'],
            'concepto_id' => ['nullable', Rule::exists('conceptos_pago', 'id')],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);

        if ($datos['aplica_a_tipo'] === ReglaComision::APLICA_GLOBAL) {
            $datos['aplica_a_id'] = null;
        } elseif (($datos['aplica_a_id'] ?? null) === null) {
            return back()->with('error', 'Elige a qué carrera u oferta aplica.');
        }

        // Un porcentaje sin concepto no dice de qué: ¿de la inscripción, de la
        // colegiatura, del año completo? Se exige antes de guardarlo.
        if ($datos['modo'] === ReglaComision::MODO_PORCENTAJE && ($datos['concepto_id'] ?? null) === null) {
            return back()->with('error', 'Un porcentaje necesita decir sobre qué concepto se calcula.');
        }

        ReglaComision::create($datos);

        return back()->with('exito', 'Regla de comisión creada.');
    }

    public function eliminarRegla(ReglaComision $regla): RedirectResponse
    {
        // Una regla que ya pagó comisiones no se borra: los renglones
        // devengados explican de dónde salió cada monto.
        if (Comision::where('regla_id', $regla->id)->exists()) {
            return back()->with(
                'error',
                'Esta regla ya devengó comisiones. Ponle fecha de fin o desactívala para retirarla.'
            );
        }

        $regla->delete();

        return back()->with('exito', 'Regla eliminada.');
    }

    /**
     * Las publicaciones: qué formulario se ofrece en la web de la escuela.
     *
     * Se muestra el `<iframe>` listo para copiar. La escuela no debería tener
     * que armar el HTML ni acordarse del dominio de su tenant.
     */
    public function publicaciones(Request $request): Response
    {
        return Inertia::render('Promocion/Publicaciones', [
            'publicaciones' => FormularioPublico::query()
                ->with('formulario:id,clave,titulo,version', 'origen:id,nombre', 'etapa:id,nombre',
                    'oferta.carrera:id,nombre', 'campus:id,nombre', 'asesor.persona:id,nombre,primer_apellido,segundo_apellido')
                ->orderByDesc('id')
                ->get()
                ->map(fn (FormularioPublico $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'titulo' => $p->titulo,
                    'modo' => $p->modo,
                    'formulario_id' => $p->formulario_id,
                    'token' => $p->token,
                    'url' => url('/p/'.$p->token),
                    'formulario' => $p->formulario?->titulo.' v'.$p->formulario?->version,
                    'origen' => $p->origen?->nombre,
                    'etapa' => $p->etapa?->nombre,
                    'oferta' => $p->oferta?->carrera?->nombre,
                    'campus' => $p->campus?->nombre,
                    'asesor' => $p->asesor?->persona?->nombreCompleto(),
                    'activo' => $p->activo,
                    'abierto' => $p->estaAbierto(),
                    'vigente_desde' => $p->vigente_desde?->toDateString(),
                    'vigente_hasta' => $p->vigente_hasta?->toDateString(),
                    'visitas' => $p->visitas,
                    'envios' => $p->envios,
                ]),
            'formularios' => Formulario::query()->orderBy('titulo')->orderByDesc('version')
                ->get(['id', 'clave', 'titulo', 'version'])
                ->map(fn (Formulario $f) => ['id' => $f->id, 'nombre' => $f->titulo.' (v'.$f->version.')']),
            'origenes' => OrigenAspirante::activos()->orderBy('nombre')->get(['id', 'nombre', 'autogestivo']),
            'etapas' => EtapaCrm::orderBy('orden')->get(['id', 'nombre']),
            'campus' => Campus::orderBy('nombre')->get(['id', 'nombre']),
            'ofertas' => Oferta::with('carrera:id,nombre', 'campus:id,nombre')->get()
                ->map(fn ($o) => ['id' => $o->id, 'nombre' => ($o->carrera?->nombre ?? '—').' · '.($o->campus?->nombre ?? '—')]),
            'promotores' => Asesor::query()->with('persona:id,nombre,primer_apellido,segundo_apellido')->get()
                ->map(fn (Asesor $a) => ['persona_id' => $a->persona_id, 'nombre' => $a->persona?->nombreCompleto()]),
        ]);
    }

    public function guardarPublicacion(Request $request): RedirectResponse
    {
        FormularioPublico::create($this->validarPublicacion($request));

        return back()->with('exito', 'Formulario publicado. Copia el código para embeberlo en tu página.');
    }

    public function actualizarPublicacion(Request $request, FormularioPublico $publicacion): RedirectResponse
    {
        $publicacion->update($this->validarPublicacion($request));

        return back()->with('exito', 'Publicación actualizada.');
    }

    /**
     * Una publicación que ya recibió gente NO se borra: sus prospectos vienen
     * de ahí y perder de dónde llegaron es perder la medición de la campaña.
     * Se desactiva, que además es lo que se quiere cuando cierra.
     */
    public function eliminarPublicacion(FormularioPublico $publicacion): RedirectResponse
    {
        if ($publicacion->envios > 0) {
            return back()->with(
                'error',
                "Esta publicación ya recibió {$publicacion->envios} solicitudes. Desactívala en vez de borrarla: "
                .'si desaparece, se pierde de dónde llegaron esos prospectos.'
            );
        }

        $publicacion->delete();

        return back()->with('exito', 'Publicación eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validarPublicacion(Request $request): array
    {
        return $request->validate([
            'formulario_id' => ['required', Rule::exists('formularios', 'id')],
            'nombre' => ['required', 'string', 'max:150'],
            'titulo' => ['required', 'string', 'max:200'],
            'modo' => ['required', Rule::in([FormularioPublico::MODO_CAPTACION, FormularioPublico::MODO_INSCRIPCION])],
            'bienvenida' => ['nullable', 'string', 'max:1000'],
            'gracias' => ['nullable', 'string', 'max:1000'],
            'origen_id' => ['nullable', Rule::exists('origenes_aspirante', 'id')],
            'etapa_crm_id' => ['nullable', Rule::exists('etapas_crm', 'id')],
            'campus_id' => ['nullable', Rule::exists('campus', 'id')],
            'oferta_id' => ['nullable', Rule::exists('oferta', 'id')],
            'asesor_persona_id' => ['nullable', Rule::exists('asesores', 'persona_id')],
            'activo' => ['boolean'],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);
    }

    /** Catálogos que consume la ficha del aspirante. */
    public function catalogos(): array
    {
        return [
            'tiposSeguimiento' => TipoSeguimiento::activos()->orderBy('id')
                ->get(['id', 'nombre', 'exige_proximo_contacto']),
            'etapas' => EtapaCrm::orderBy('orden')->get(['id', 'nombre', 'orden']),
            'origenes' => OrigenAspirante::activos()->orderBy('nombre')->get(['id', 'nombre', 'autogestivo']),
            'promotores' => Asesor::query()
                ->with('persona:id,nombre,primer_apellido,segundo_apellido')
                ->get()
                ->map(fn (Asesor $a) => [
                    'persona_id' => $a->persona_id,
                    'nombre' => $a->persona?->nombreCompleto(),
                ])->values(),
        ];
    }

    /**
     * Un promotor solo toca los prospectos que le asignaron. El permiso dice
     * qué puede hacer; la asignación, sobre quién.
     */
    private function autorizarProspecto(Request $request, Aspirante $aspirante): void
    {
        if ($request->user()->can('gestionar-promocion')) {
            return;
        }

        $suyo = $aspirante->asesores()
            ->where('asesores.persona_id', $request->user()->persona_id)
            ->exists();

        abort_unless($suyo, 403, 'Este prospecto no está asignado a ti.');
    }

    /** El historial de contacto de un prospecto, para su ficha. */
    public function historial(Aspirante $aspirante): array
    {
        return $aspirante->seguimientos()
            ->with(['tipo:id,nombre', 'persona:id,nombre,primer_apellido,segundo_apellido', 'etapa:id,nombre'])
            ->get()
            ->map(fn (SeguimientoAspirante $s) => [
                'id' => $s->id,
                'tipo' => $s->tipo?->nombre,
                'quien' => $s->persona?->nombreCompleto(),
                'etapa' => $s->etapa?->nombre,
                'nota' => $s->nota,
                'proximo_contacto' => $s->proximo_contacto?->toDateString(),
                'momento' => $s->momento?->toDateTimeString(),
            ])->all();
    }
}
