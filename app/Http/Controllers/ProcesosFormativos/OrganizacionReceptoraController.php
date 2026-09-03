<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Landlord\EntidadFederativa;
use App\Models\ProcesosFormativos\OrganizacionAlcance;
use App\Models\ProcesosFormativos\OrganizacionContacto;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\SectorOrganizacion;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El padrón de organizaciones receptoras.
 *
 * ── El padrón es INSTITUCIONAL, no de un campus ────────────────────────────
 * No se acota por campus, y es deliberado: una dependencia de gobierno no
 * pertenece a un plantel, y un coordinador que no la viera la daría de alta
 * otra vez —el duplicado que reparte los expedientes entre dos filas y hace que
 * ningún reporte cuadre—. Mismo criterio que las cuentas bancarias y el cierre
 * fiscal, que tampoco se acotan.
 *
 * **Lo que SÍ se acota es a quién se le puede mandar**, y eso vive en
 * `organizacion_alcances`: la comprobación va en la ASIGNACIÓN (fase 4), que es
 * donde tiene consecuencias. Aquí el alcance se puede usar como filtro para no
 * leer un padrón entero, pero no como candado.
 *
 * ── Se APAGA con su situación, no se borra ─────────────────────────────────
 * Sus expedientes históricos son la prueba de dónde estuvo alguien. Por eso no
 * hay `destroy` sino una situación que deja de aceptar asignaciones — y la
 * bandera `acepta_asignaciones` es lo que el código consulta, nunca la clave.
 */
class OrganizacionReceptoraController extends Controller
{
    /** Cuántas por página. Público para que la prueba lo sepa sin adivinar. */
    public const POR_PAGINA = 25;

    public function index(Request $peticion): Response
    {
        $filtros = $peticion->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'situacion_id' => ['nullable', 'integer'],
            'sector_id' => ['nullable', 'integer'],
            'campus_id' => ['nullable', 'integer'],
        ]);

        $consulta = OrganizacionReceptora::query()
            ->with(['sector:id,nombre', 'tipo:id,nombre', 'situacion:id,nombre,acepta_asignaciones'])
            ->withCount([
                'contactos',
                'plazas',
                // Los convenios que HOY amparan: es lo que decide si se le puede
                // mandar a alguien, y contarlos todos diría que sí teniendo sólo
                // convenios vencidos.
                'convenios as convenios_vigentes_count' => fn (Builder $q) => $q->vigentes(),
            ]);

        $this->aplicarFiltros($consulta, $filtros);

        $organizaciones = $consulta
            ->orderBy('razon_social')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (OrganizacionReceptora $o) => [
                'id' => $o->id,
                'razon_social' => $o->razon_social,
                'nombre_comercial' => $o->nombre_comercial,
                'rfc' => $o->rfc,
                'sector' => $o->sector?->nombre,
                'tipo' => $o->tipo?->nombre,
                'situacion' => $o->situacion?->nombre,
                'recibe' => (bool) $o->situacion?->acepta_asignaciones,
                'municipio' => $o->municipio,
                'contactos' => $o->contactos_count,
                'plazas' => $o->plazas_count,
                'convenios_vigentes' => $o->convenios_vigentes_count,
            ]);

        return Inertia::render('Procesos/Organizaciones/Index', [
            'organizaciones' => $organizaciones,
            'filtros' => (object) $filtros,
            'catalogos' => $this->catalogos(),
            'puedeEditar' => $peticion->user()->can('gestionar-organizaciones-receptoras'),
        ]);
    }

    public function show(Request $peticion, OrganizacionReceptora $organizacion): Response
    {
        $organizacion->load([
            'sector:id,nombre',
            'tipo:id,nombre',
            'situacion:id,nombre,acepta_asignaciones',
            'contactos',
            'alcances.campus:id,nombre',
            'alcances.programaAcademico:id,nombre',
            'alcances.tipoProceso:id,nombre',
            'convenios.tipo:id,nombre',
            'convenios.situacion:id,nombre,ampara_asignaciones',
            'plazas.tipoProceso:id,nombre',
        ]);

        return Inertia::render('Procesos/Organizaciones/Detalle', [
            'organizacion' => [
                'id' => $organizacion->id,
                'razon_social' => $organizacion->razon_social,
                'nombre_comercial' => $organizacion->nombre_comercial,
                'rfc' => $organizacion->rfc,
                'sector_id' => $organizacion->sector_id,
                'tipo_id' => $organizacion->tipo_id,
                'situacion_id' => $organizacion->situacion_id,
                'calle' => $organizacion->calle,
                'colonia' => $organizacion->colonia,
                'municipio' => $organizacion->municipio,
                'entidad_federativa_id' => $organizacion->entidad_federativa_id,
                'codigo_postal' => $organizacion->codigo_postal,
                'representante' => $organizacion->representante,
                'sitio_web' => $organizacion->sitio_web,
                'telefono' => $organizacion->telefono,
                'correo' => $organizacion->correo,
                'cupo_total' => $organizacion->cupo_total,
                'notas' => $organizacion->notas,
                'situacion' => $organizacion->situacion?->nombre,
                'recibe' => (bool) $organizacion->situacion?->acepta_asignaciones,
            ],
            'contactos' => $organizacion->contactos->map(fn (OrganizacionContacto $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'cargo' => $c->cargo,
                'correo' => $c->correo,
                'telefono' => $c->telefono,
                'es_principal' => $c->es_principal,
                'es_supervisor' => $c->es_supervisor,
            ])->values(),
            'alcances' => $organizacion->alcances->map(fn (OrganizacionAlcance $a) => [
                'id' => $a->id,
                'campus_id' => $a->campus_id,
                'programa_academico_id' => $a->programa_academico_id,
                'tipo_proceso_id' => $a->tipo_proceso_id,
                'texto' => $a->comoSeLee(),
            ])->values(),
            'convenios' => $organizacion->convenios
                ->sortByDesc('vigente_desde')
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'folio' => $c->folio,
                    'version' => $c->version,
                    'tipo' => $c->tipo?->nombre,
                    'situacion' => $c->situacion?->nombre,
                    'vigente_desde' => $c->vigente_desde?->toDateString(),
                    'vigente_hasta' => $c->vigente_hasta?->toDateString(),
                    'vigente' => $c->estaVigente(),
                    'vencido' => $c->estaVencido(),
                    'dias_para_vencer' => $c->diasParaVencer(),
                ])->values(),
            'plazas' => $organizacion->plazas->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'tipo' => $p->tipoProceso?->nombre,
                'cupo' => $p->cupo,
                'cupo_ocupado' => $p->cupo_ocupado,
                'abierta' => $p->abierta,
                'admite' => $p->admiteA(),
            ])->values(),
            'catalogos' => $this->catalogos(),
            'puedeEditar' => $peticion->user()->can('gestionar-organizaciones-receptoras'),
        ]);
    }

    /**
     * Alta y edición, el mismo camino.
     *
     * Dos casi iguales es como se llega a que el alta pida un campo que la
     * edición no ofrece — la lección de las vacantes de la bolsa.
     */
    public function guardar(Request $peticion, ?OrganizacionReceptora $organizacion = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            /*
             * Opcional pero ÚNICO, y lo detiene la VALIDACIÓN y no el índice:
             * quien captura tiene que leer el mensaje en su formulario, no un
             * error de SQL. Es la lección del RFC de las empresas de la bolsa.
             */
            'rfc' => [
                'nullable', 'string', 'max:13',
                Rule::unique('organizaciones_receptoras', 'rfc')
                    ->ignore($organizacion?->id)
                    ->whereNull('deleted_at'),
            ],
            'sector_id' => ['nullable', 'integer', 'exists:sectores_organizacion,id'],
            'tipo_id' => ['nullable', 'integer', 'exists:tipos_organizacion,id'],
            'situacion_id' => ['required', 'integer', 'exists:situaciones_organizacion,id'],
            'calle' => ['nullable', 'string', 'max:255'],
            'colonia' => ['nullable', 'string', 'max:150'],
            'municipio' => ['nullable', 'string', 'max:150'],
            'entidad_federativa_id' => ['nullable', 'integer'],
            'codigo_postal' => ['nullable', 'string', 'max:10'],
            'representante' => ['nullable', 'string', 'max:255'],
            'sitio_web' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'cupo_total' => ['nullable', 'integer', 'min:1'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'rfc.unique' => 'Ya hay una organización con ese RFC. Búscala en el padrón en vez de crearla otra vez.',
            'sitio_web.url' => 'El sitio web va completo, con http:// o https://',
        ]);

        $organizacion ??= new OrganizacionReceptora;
        $organizacion->fill($datos)->save();

        return $organizacion->wasRecentlyCreated
            ? to_route('tenant.procesos.organizaciones.ver', $organizacion)->with('exito', 'Organización dada de alta.')
            : back(303)->with('exito', 'Organización actualizada.');
    }

    /**
     * Un contacto de la organización.
     *
     * **Un solo principal**, degradando al anterior en la misma transacción:
     * con dos, la pantalla enseña el que salga primero. Es la lección del
     * padrón de empleadores de la bolsa.
     */
    public function guardarContacto(
        Request $peticion,
        OrganizacionReceptora $organizacion,
        ?OrganizacionContacto $contacto = null,
    ): RedirectResponse {
        $this->contactoEsDeLaOrganizacion($organizacion, $contacto);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'cargo' => ['nullable', 'string', 'max:150'],
            'correo' => ['nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'es_principal' => ['boolean'],
            'es_supervisor' => ['boolean'],
        ]);

        $datos['es_principal'] = $peticion->boolean('es_principal');
        $datos['es_supervisor'] = $peticion->boolean('es_supervisor');

        DB::transaction(function () use ($organizacion, $contacto, $datos) {
            if ($datos['es_principal']) {
                $organizacion->contactos()
                    ->when($contacto !== null, fn ($q) => $q->whereKeyNot($contacto->id))
                    ->update(['es_principal' => false]);
            }

            $contacto === null
                ? $organizacion->contactos()->create($datos)
                : $contacto->update($datos);
        });

        return back(303)->with('exito', 'Contacto guardado.');
    }

    public function eliminarContacto(OrganizacionReceptora $organizacion, OrganizacionContacto $contacto): RedirectResponse
    {
        $this->contactoEsDeLaOrganizacion($organizacion, $contacto);

        $contacto->delete();

        return back(303)->with('exito', 'Contacto retirado.');
    }

    /**
     * Un alcance: hasta dónde llega esta organización.
     *
     * Los tres campos son opcionales y al menos uno tiene que venir: una fila
     * con los tres en null diría «cualquier campus, cualquier programa,
     * cualquier proceso», que es exactamente lo que significa NO tener filas.
     * Guardarla haría creer que se acotó algo.
     */
    public function agregarAlcance(Request $peticion, OrganizacionReceptora $organizacion): RedirectResponse
    {
        $datos = $peticion->validate([
            'campus_id' => ['nullable', 'integer', 'exists:campus,id'],
            'programa_academico_id' => ['nullable', 'integer', 'exists:programas_academicos,id'],
            'tipo_proceso_id' => ['nullable', 'integer', 'exists:tipos_proceso_formativo,id'],
        ]);

        AvisoParaElUsuario::si(
            collect($datos)->filter()->isEmpty(),
            422,
            'Un alcance tiene que acotar algo: elige campus, programa o tipo de proceso. '
            .'Sin ninguna condición ya alcanza a todo, que es lo que pasa cuando no hay alcances.',
        );

        $organizacion->alcances()->create($datos);

        return back(303)->with('exito', 'Alcance agregado.');
    }

    public function eliminarAlcance(OrganizacionReceptora $organizacion, OrganizacionAlcance $alcance): RedirectResponse
    {
        AvisoParaElUsuario::aMenosQue(
            $alcance->organizacion_id === $organizacion->id,
            404,
            'Ese alcance no es de esta organización.',
        );

        $alcance->delete();

        return back(303)->with('exito', 'Alcance retirado. Si no queda ninguno, la organización vuelve a alcanzar a todo.');
    }

    /** @param  array<string, mixed>  $filtros */
    private function aplicarFiltros(Builder $consulta, array $filtros): void
    {
        $consulta
            ->when(
                ($filtros['busca'] ?? '') !== '',
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('razon_social', 'like', '%'.$filtros['busca'].'%')
                    ->orWhere('nombre_comercial', 'like', '%'.$filtros['busca'].'%')
                    ->orWhere('rfc', 'like', '%'.$filtros['busca'].'%')),
            )
            ->when(($filtros['situacion_id'] ?? null) !== null, fn (Builder $q) => $q->where('situacion_id', $filtros['situacion_id']))
            ->when(($filtros['sector_id'] ?? null) !== null, fn (Builder $q) => $q->where('sector_id', $filtros['sector_id']))
            /*
             * El filtro por campus mira los ALCANCES, e incluye las que no
             * declaran ninguno: ésas alcanzan a todo, así que también sirven en
             * ese campus. Dejarlas fuera escondería justo las más usadas.
             */
            ->when(
                ($filtros['campus_id'] ?? null) !== null,
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->whereHas('alcances', fn (Builder $a) => $a->where('campus_id', $filtros['campus_id']))
                    ->orWhereDoesntHave('alcances')),
            );
    }

    private function contactoEsDeLaOrganizacion(OrganizacionReceptora $organizacion, ?OrganizacionContacto $contacto): void
    {
        /*
         * Las dos ids viajan por la URL, así que se comprueba la PAREJA. Con
         * sólo la del contacto, cualquiera con una organización propia tendría
         * una puerta lateral a los contactos de otra.
         */
        AvisoParaElUsuario::si(
            $contacto !== null && $contacto->exists && $contacto->organizacion_id !== $organizacion->id,
            404,
            'Ese contacto no es de esta organización.',
        );
    }

    /** @return array<string, mixed> */
    private function catalogos(): array
    {
        return [
            'sectores' => SectorOrganizacion::query()->activos()->get(['id', 'nombre']),
            'tipos' => TipoOrganizacion::query()->activos()->get(['id', 'nombre']),
            'situaciones' => SituacionOrganizacion::query()->activos()->get(['id', 'nombre', 'acepta_asignaciones']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'programas' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre']),
            'tiposProceso' => TipoProcesoFormativo::query()->activos()->get(['id', 'nombre']),
            'entidades' => EntidadFederativa::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
