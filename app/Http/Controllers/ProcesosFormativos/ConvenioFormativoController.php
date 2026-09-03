<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProcesosFormativos;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\ProcesosFormativos\ConvenioFormativo;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\SituacionConvenioFormativo;
use App\Models\ProcesosFormativos\TipoConvenioFormativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los convenios con las organizaciones receptoras.
 *
 * ── VENCIDO no es lo mismo que SUSPENDIDO ──────────────────────────────────
 * La fecha dice lo primero y la situación lo segundo, y el listado enseña las
 * dos: un convenio con la situación «vigente» y la fecha pasada se ve bien en
 * cualquier pantalla que sólo mire una de las dos, y seguiría amparando
 * asignaciones nuevas.
 *
 * ── Renovar CREA otra fila; la vieja NO se edita ───────────────────────────
 * Un convenio es un papel fechado: cambiarle las fechas al renovarlo borraría
 * bajo qué acuerdo estuvo cada alumno que ya pasó por ahí. La renovación apunta
 * a la anterior y las dos se conservan — el molde del acta de corrección y de
 * la nota de crédito.
 *
 * ── El documento va al disco PRIVADO ───────────────────────────────────────
 * Un convenio trae nombres, firmas y a veces domicilios. Nunca a `public/`, y
 * la descarga pasa por un controlador que comprueba quién pide: guardar en
 * privado sin eso sólo mueve la pregunta a la ruta que lo sirve.
 */
class ConvenioFormativoController extends Controller
{
    public const POR_PAGINA = 25;

    /** Con cuántos días de anticipación se avisa de un vencimiento. */
    public const DIAS_AVISO = 60;

    public function index(Request $peticion): Response
    {
        $filtros = $peticion->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'situacion_id' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::in(['vigentes', 'por_vencer', 'vencidos'])],
        ]);

        $consulta = ConvenioFormativo::query()
            ->with([
                'organizacion:id,razon_social,nombre_comercial',
                'tipo:id,nombre',
                'situacion:id,nombre,ampara_asignaciones',
            ])
            ->withExists('renovacion as fue_renovado');

        $consulta
            ->when(
                ($filtros['busca'] ?? '') !== '',
                fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('folio', 'like', '%'.$filtros['busca'].'%')
                    ->orWhereHas('organizacion', fn (Builder $o) => $o
                        ->where('razon_social', 'like', '%'.$filtros['busca'].'%')
                        ->orWhere('nombre_comercial', 'like', '%'.$filtros['busca'].'%'))),
            )
            ->when(($filtros['situacion_id'] ?? null) !== null, fn (Builder $q) => $q->where('situacion_id', $filtros['situacion_id']))
            ->when(($filtros['estado'] ?? null) === 'vigentes', fn (Builder $q) => $q->vigentes())
            ->when(($filtros['estado'] ?? null) === 'por_vencer', fn (Builder $q) => $q->porVencer(self::DIAS_AVISO))
            ->when(
                ($filtros['estado'] ?? null) === 'vencidos',
                fn (Builder $q) => $q->whereNotNull('vigente_hasta')->whereDate('vigente_hasta', '<', now()->toDateString()),
            );

        return Inertia::render('Procesos/Convenios/Index', [
            'convenios' => $consulta
                ->orderByDesc('vigente_desde')
                ->paginate(self::POR_PAGINA)
                ->withQueryString()
                ->through(fn (ConvenioFormativo $c) => $this->paraPantalla($c)),

            'filtros' => (object) $filtros,
            'diasAviso' => self::DIAS_AVISO,

            /*
             * Cuántos vencen pronto, SIN el filtro puesto.
             *
             * Es el número que hace que alguien entre a esta pantalla. Contarlo
             * sobre la consulta ya filtrada daría cero en cuanto se filtre por
             * otra cosa, y la alerta desaparecería justo cuando se busca.
             */
            'porVencer' => ConvenioFormativo::query()->porVencer(self::DIAS_AVISO)->count(),

            'catalogos' => [
                'organizaciones' => OrganizacionReceptora::query()
                    ->orderBy('razon_social')
                    ->get(['id', 'razon_social', 'nombre_comercial'])
                    ->map(fn (OrganizacionReceptora $o) => ['id' => $o->id, 'nombre' => $o->comoSeLeConoce()]),
                'tipos' => TipoConvenioFormativo::query()->activos()->get(['id', 'nombre']),
                'situaciones' => SituacionConvenioFormativo::query()->activos()->get(['id', 'nombre', 'ampara_asignaciones']),
            ],
            'puedeEditar' => $peticion->user()->can('gestionar-convenios-formativos'),
        ]);
    }

    public function guardar(Request $peticion, ?ConvenioFormativo $convenio = null): RedirectResponse
    {
        $datos = $this->validar($peticion, $convenio);

        $archivo = $peticion->file('documento');

        DB::transaction(function () use (&$convenio, $datos, $archivo) {
            $convenio ??= new ConvenioFormativo;
            $convenio->fill($datos);

            if ($archivo !== null) {
                $anterior = $convenio->documento_ruta;

                $convenio->documento_ruta = $archivo->store(
                    sprintf('convenios-formativos/%d', $datos['organizacion_id']),
                    'local',
                );

                // El anterior se retira: un convenio tiene UN documento, y
                // dejarlo acumularía papeles que nadie va a volver a mirar.
                $anterior === null || Storage::disk('local')->delete($anterior);
            }

            $convenio->save();
        });

        return back(303)->with('exito', 'Convenio guardado.');
    }

    /**
     * Renovar: una fila NUEVA que apunta a la anterior.
     *
     * No se edita la vieja. Y la anterior no se «cierra» sola: su fecha de
     * término ya dice hasta cuándo valió, y tocarla borraría bajo qué acuerdo
     * estuvo quien pasó por ahí.
     */
    public function renovar(Request $peticion, ConvenioFormativo $convenio): RedirectResponse
    {
        AvisoParaElUsuario::si(
            $convenio->renovacion()->exists(),
            422,
            'Ese convenio ya se renovó. La renovación es la que se vuelve a renovar, no la anterior.',
        );

        $datos = $peticion->validate([
            'folio' => ['required', 'string', 'max:100'],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after:vigente_desde'],
            'situacion_id' => ['required', 'integer', 'exists:situaciones_convenio_formativo,id'],
            'documento' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'vigente_hasta.after' => 'La vigencia termina después de que empieza.',
            'documento.mimes' => 'El convenio va en PDF.',
        ]);

        $archivo = $peticion->file('documento');

        $nuevo = DB::transaction(function () use ($convenio, $datos, $archivo) {
            $nuevo = new ConvenioFormativo;

            $nuevo->fill([
                'organizacion_id' => $convenio->organizacion_id,
                'tipo_convenio_id' => $convenio->tipo_convenio_id,
                'folio' => $datos['folio'],
                'version' => $convenio->version + 1,
                'convenio_anterior_id' => $convenio->id,
                'vigente_desde' => $datos['vigente_desde'],
                'vigente_hasta' => $datos['vigente_hasta'] ?? null,
                'situacion_id' => $datos['situacion_id'],
                'notas' => $datos['notas'] ?? null,
            ]);

            if ($archivo !== null) {
                $nuevo->documento_ruta = $archivo->store(
                    sprintf('convenios-formativos/%d', $convenio->organizacion_id),
                    'local',
                );
            }

            $nuevo->save();

            return $nuevo;
        });

        return back(303)->with(
            'exito',
            "Renovado como versión {$nuevo->version}. El convenio anterior se conserva tal cual.",
        );
    }

    /**
     * La descarga del documento.
     *
     * Va por aquí y no por una URL del disco: guardar en privado sin un
     * controlador que compruebe quién pide sólo mueve la pregunta de sitio.
     */
    public function descargar(ConvenioFormativo $convenio): StreamedResponse
    {
        abort_if($convenio->documento_ruta === null, 404);
        abort_unless(Storage::disk('local')->exists($convenio->documento_ruta), 404);

        return Storage::disk('local')->download(
            $convenio->documento_ruta,
            sprintf('convenio-%s-v%d.pdf', $convenio->folio, $convenio->version),
        );
    }

    /** @return array<string, mixed> */
    private function validar(Request $peticion, ?ConvenioFormativo $convenio): array
    {
        return $peticion->validate([
            'organizacion_id' => ['required', 'integer', 'exists:organizaciones_receptoras,id'],
            'tipo_convenio_id' => ['nullable', 'integer', 'exists:tipos_convenio_formativo,id'],
            'folio' => [
                'required', 'string', 'max:100',
                /*
                 * El folio no se repite dentro de una organización y una
                 * versión. Entre organizaciones sí puede: cada una numera como
                 * quiere, y un único global obligaría a inventar prefijos.
                 */
                Rule::unique('convenios_formativos', 'folio')
                    ->where('organizacion_id', (int) $peticion->input('organizacion_id'))
                    ->where('version', $convenio?->version ?? 1)
                    ->ignore($convenio?->id)
                    ->whereNull('deleted_at'),
            ],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after:vigente_desde'],
            'situacion_id' => ['required', 'integer', 'exists:situaciones_convenio_formativo,id'],
            'documento' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'folio.unique' => 'Esa organización ya tiene un convenio con ese folio en esta versión.',
            'vigente_hasta.after' => 'La vigencia termina después de que empieza.',
            'documento.mimes' => 'El convenio va en PDF.',
        ]);
    }

    /** @return array<string, mixed> */
    private function paraPantalla(ConvenioFormativo $c): array
    {
        return [
            'id' => $c->id,
            'organizacion' => $c->organizacion?->comoSeLeConoce(),
            'organizacion_id' => $c->organizacion_id,
            'folio' => $c->folio,
            'version' => $c->version,
            'tipo' => $c->tipo?->nombre,
            'tipo_convenio_id' => $c->tipo_convenio_id,
            'situacion' => $c->situacion?->nombre,
            'situacion_id' => $c->situacion_id,
            'vigente_desde' => $c->vigente_desde?->toDateString(),
            'vigente_hasta' => $c->vigente_hasta?->toDateString(),
            // Las TRES, porque significan cosas distintas y una sola engaña.
            'vigente' => $c->estaVigente(),
            'vencido' => $c->estaVencido(),
            'aun_no_empieza' => $c->aunNoEmpieza(),
            'ampara' => (bool) $c->situacion?->ampara_asignaciones,
            'dias_para_vencer' => $c->diasParaVencer(),
            'tiene_documento' => $c->documento_ruta !== null,
            'fue_renovado' => (bool) $c->fue_renovado,
            'notas' => $c->notas,
        ];
    }
}
