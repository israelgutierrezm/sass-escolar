<?php

declare(strict_types=1);

namespace App\Http\Controllers\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Identidad\Rol;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\ReglaAlerta;
use App\Models\Permanencia\ReglaAlertaVersion;
use App\Permanencia\CatalogoMetricas;
use App\Services\Permanencia\IndicadoresDePermanencia;
use App\Services\Permanencia\PlantillaDeAviso;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las reglas de alerta: qué vigila la escuela y con qué umbral.
 *
 * ── Lo que esta pantalla NO es ─────────────────────────────────────────────
 * No es un editor de expresiones. La escuela elige de las MÉTRICAS que los
 * proveedores declaran y pone el umbral, el comparador, la ventana y el
 * alcance. Una caja de texto libre sería una superficie de ejecución que
 * ninguna lista negra cierra —la misma razón por la que el constructor de
 * reportes no tiene campo de SQL— y, sobre todo, **no se podría EXPLICAR**:
 * una alerta tiene que decir por qué se generó, y de una expresión libre sólo
 * se puede repetir el texto.
 *
 * ── Y no acota por campus, a propósito ─────────────────────────────────────
 * Configurar qué vigila la escuela es una decisión institucional, como el
 * cierre fiscal o el padrón de organizaciones receptoras. Un coordinador de
 * plantel que pudiera cambiar el umbral de asistencia «de su campus» estaría
 * decidiendo a quién se le hace seguimiento, y eso no es un ajuste local. Lo
 * que sí se acota por campus es la BANDEJA de alertas, que llega en la fase 3.
 */
class ReglaAlertaController extends Controller
{
    use AcotaPorCampus;

    public function index(Request $peticion): Response
    {
        /*
         * La CALIBRACIÓN de cada regla, en UNA consulta agregada y no una por
         * fila: con cuarenta reglas, contar señales por regla dentro del bucle
         * es la N+1 de siempre.
         */
        $calibracion = collect(app(IndicadoresDePermanencia::class)
            ->tablero($peticion->user())['calibracion'] ?? [])
            ->keyBy('regla');

        $reglas = ReglaAlerta::query()
            ->with(['categoria:id,clave,nombre,color,sensible', 'versiones', 'campus:id,nombre',
                'programaAcademico:id,nombre', 'plan:id,nombre', 'ciclo:id,clave'])
            ->orderBy('nombre')
            ->get()
            ->map(fn (ReglaAlerta $r) => $this->paraLaLista($r, $calibracion->get($r->nombre)));

        return Inertia::render('Permanencia/Reglas/Index', [
            'reglas' => $reglas,
            /*
             * Cuántas están encendidas viaja aparte para poder decirlo arriba.
             * Ocho reglas escritas se leen como ocho reglas funcionando, y el
             * aviso de que ninguna lo está es lo que evita que una escuela se
             * crea configurada. Mismo criterio que la escalera de cobranza.
             */
            'encendidas' => $reglas->where('activa', true)->count(),
            'metricas' => $this->metricasParaLaPantalla(),
            /*
             * La ventana de la calibración se dice: «60 % de descartes» sin
             * saber sobre cuánto tiempo no significa nada.
             */
            'ventanaCalibracion' => IndicadoresDePermanencia::DIAS,
            'minimoParaCalibrar' => IndicadoresDePermanencia::MINIMO_POR_GRUPO,
            'catalogos' => $this->catalogos(),
            'puedeEditar' => $peticion->user()?->can('configurar-reglas-alerta') === true,
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        $datos = $this->validarRegla($peticion);
        $version = $this->validarVersion($peticion, $datos['metrica'] ?? null);

        $regla = DB::transaction(function () use ($datos, $version) {
            $regla = ReglaAlerta::create(array_merge(
                collect($datos)->except('metrica')->all(),
                [
                    /*
                     * El proveedor se DERIVA de la métrica y no se captura.
                     * Capturado, alguien elegiría «asistencia» con una métrica
                     * académica y la regla se guardaría sin poderse calcular
                     * jamás — y no fallaría: simplemente no levantaría nada.
                     */
                    'proveedor' => CatalogoMetricas::de($datos['metrica'])['proveedor'],
                    // Nace APAGADA aunque quien la escribe crea que ya vigila
                    // algo: encenderla es un acto aparte, después de mirar qué
                    // levanta.
                    'activa' => false,
                ],
            ));

            $regla->versiones()->create(array_merge($version, [
                'version' => 1,
                'metrica' => $datos['metrica'],
            ]));

            return $regla;
        });

        return back(303)->with('exito',
            'Se creó «'.$regla->nombre.'», apagada. Enciéndela cuando hayas revisado su umbral.');
    }

    public function update(Request $peticion, ReglaAlerta $regla): RedirectResponse
    {
        $datos = $this->validarRegla($peticion, $regla->id);

        /*
         * Sólo el ALCANCE y la identidad. Lo que MIDE vive en la versión y se
         * cambia emitiendo otra: editarlo aquí reescribiría la historia de las
         * alertas que ya se generaron con el umbral anterior, y la primera
         * pregunta al revisar un caso viejo es exactamente con qué umbral salió.
         */
        $regla->update(collect($datos)->except('metrica')->all());

        return back(303)->with('exito', 'Se guardó el alcance.');
    }

    /**
     * Emitir una versión nueva.
     *
     * No se edita la vigente: se CIERRA y se emite otra. Es el molde del acta de
     * corrección, de la nota de crédito y de la liberación formativa — y aquí es
     * lo que permite contestar, dentro de dos años, con qué umbral se levantó
     * una alerta concreta.
     */
    public function versionar(Request $peticion, ReglaAlerta $regla): RedirectResponse
    {
        $metrica = $regla->versiones->sortByDesc('version')->first()?->metrica;
        $datos = $this->validarVersion($peticion, $metrica);

        DB::transaction(function () use ($regla, $datos, $metrica) {
            $vigente = $regla->versionVigente();

            /*
             * La anterior se cierra el día ANTES de que empiece la nueva: dos
             * versiones no pueden regir a la vez, y dejar el hueco abierto haría
             * que `versionVigente()` tuviera que desempatar por fecha de alta,
             * que no es una razón.
             */
            if ($vigente !== null && $vigente->id !== null) {
                $vigente->update([
                    'vigente_hasta' => CarbonImmutable::parse($datos['vigente_desde'])
                        ->subDay()->toDateString(),
                ]);
            }

            $regla->versiones()->create(array_merge($datos, [
                'version' => (int) $regla->versiones()->max('version') + 1,
                'metrica' => $metrica,
            ]));
        });

        return back(303)->with('exito',
            'Se emitió una versión nueva. Las alertas abiertas conservan la anterior.');
    }

    /**
     * Encender o apagar.
     *
     * Apagar NO borra las alertas que la regla levantó: quedan como estaban y el
     * motor las marcará OBSOLETAS en su siguiente corrida. Cerrarlas aquí diría
     * que la situación se resolvió, y no se resolvió nada: se dejó de vigilar.
     */
    public function alternar(Request $peticion, ReglaAlerta $regla): RedirectResponse
    {
        $encender = $peticion->boolean('activa');

        /*
         * La guarda va ANTES de escribir, y no es un detalle de estilo.
         *
         * Escrita después, la regla se encendía, la excepción salía, y quien la
         * pulsó leía «no se puede encender» sobre una regla que había quedado
         * encendida. Un rechazo que no rechaza es peor que ninguno: enseña a no
         * creerle a los avisos.
         */
        AvisoParaElUsuario::si(
            $encender && $regla->versionVigente() === null,
            422,
            'No se puede encender: no tiene ninguna versión vigente hoy. Emite una antes.',
        );

        $regla->update(['activa' => $encender]);

        return $encender
            ? back(303)->with('exito', 'Se encendió. Empezará a evaluarse en la próxima corrida.')
            : back(303)->with('exito',
                'Se apagó. Las alertas que ya levantó no se borran: dejarán de vigilarse.');
    }

    /**
     * Borrar sólo la que nunca levantó nada.
     *
     * Una regla que ya generó alertas es historia: sus alertas la nombran, y sin
     * ella no se podría explicar por qué se levantaron. Se APAGA.
     */
    public function destroy(ReglaAlerta $regla): RedirectResponse
    {
        if ($this->tieneAlertas($regla)) {
            return back(303)->with('error',
                'No se puede eliminar: ya levantó alertas y sus alertas la nombran. Apágala.');
        }

        DB::transaction(function () use ($regla) {
            $regla->versiones()->delete();
            $regla->delete();
        });

        return back(303)->with('exito', 'Se eliminó.');
    }

    /** @return array<string, mixed> */
    /**
     * @param  array<string, mixed>|null  $calibracion  cuánto se descarta de esta regla
     */
    private function paraLaLista(ReglaAlerta $regla, ?array $calibracion = null): array
    {
        $vigente = $regla->versionVigente();

        return [
            'id' => $regla->id,
            'nombre' => $regla->nombre,
            'descripcion' => $regla->descripcion,
            'activa' => $regla->activa,
            'proveedor' => $regla->proveedor,
            'categoria' => $regla->categoria?->only(['id', 'clave', 'nombre', 'color', 'sensible']),
            'alcance' => $regla->comoSeLeeElAlcance(),
            'ejes' => $regla->only([
                'campus_id', 'nivel_estudios_id', 'programa_academico_id', 'plan_id',
                'ciclo_id', 'situacion_alumno_id', 'modalidad',
                'generacion_desde', 'generacion_hasta', 'asignatura_id',
            ]),
            'versiones' => $regla->versiones
                ->sortByDesc('version')
                ->values()
                ->map(fn (ReglaAlertaVersion $v) => [
                    'id' => $v->id,
                    'version' => $v->version,
                    'rige' => $vigente?->id === $v->id,
                    'vigente_desde' => $v->vigente_desde?->toDateString(),
                    'vigente_hasta' => $v->vigente_hasta?->toDateString(),
                    'condicion' => $v->comoSeLee(),
                    'metrica' => $v->metrica,
                    'comparador' => $v->comparador,
                    'umbral' => $v->umbral,
                    'umbral_fuente' => $v->umbral_fuente,
                    'ventana_tipo' => $v->ventana_tipo,
                    'ventana_valor' => $v->ventana_valor,
                    'cobertura_minima' => $v->cobertura_minima,
                    'severidad' => $v->severidad,
                    'peso' => $v->peso,
                    'cooldown_dias' => $v->cooldown_dias,
                    'sla_horas' => $v->sla_horas,
                    'avisa_al_alumno' => $v->avisa_al_alumno,
                    'avisa_a_la_escuela' => $v->avisa_a_la_escuela,
                    'plantilla_aviso' => $v->plantilla_aviso,
                    'notas' => $v->notas,
                ])->all(),
            /*
             * Sin versión vigente la regla no puede medir nada, y hay que
             * decirlo AQUÍ: una regla encendida sin versión se ve idéntica a una
             * que funciona, y no levanta nada nunca.
             */
            'sin_version_vigente' => $vigente === null,
            /*
             * ── Y cuánto de lo suyo se descarta, AQUÍ ─────────────────────
             * En un reporte aparte no lo mira nadie hasta que ya nadie cree en
             * la bandeja. Quien calibra el umbral tiene que verlo en la misma
             * pantalla donde lo cambia. En null cuando no hay suficientes
             * revisadas: un porcentaje sobre tres casos parece un dato y no lo
             * es.
             */
            'calibracion' => $calibracion,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function metricasParaLaPantalla(): array
    {
        $salida = [];

        foreach (CatalogoMetricas::todas() as $clave => $m) {
            $salida[] = array_merge($m, [
                'clave' => $clave,
                'comparador_sugerido' => CatalogoMetricas::comparadorSugerido($clave),
            ]);
        }

        return $salida;
    }

    /** @return array<string, mixed> */
    private function catalogos(): array
    {
        return [
            'categorias' => CategoriaSenal::query()->activas()->get(['id', 'clave', 'nombre', 'color', 'sensible']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'niveles' => NivelEstudio::query()->activos()->orderBy('nombre')->get(['id', 'nombre']),
            'programas' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre']),
            'planes' => PlanEstudio::query()->with('programaAcademico:id,nombre')->orderBy('nombre')
                ->get(['id', 'nombre', 'programa_academico_id'])
                ->map(fn (PlanEstudio $p) => [
                    'id' => $p->id,
                    // Los planes se llaman por su año, así que veinte «Plan 2016»
                    // son indistinguibles. Es la lección de la fase 3 formativa.
                    'nombre' => $p->nombre.' · '.($p->programaAcademico?->nombre ?? 'sin programa'),
                    'programa_academico_id' => $p->programa_academico_id,
                ]),
            'ciclos' => Ciclo::query()->orderByDesc('fecha_inicio')->limit(30)->get(['id', 'clave']),
            'situaciones' => SituacionAlumno::query()->orderBy('nombre')->get(['id', 'nombre']),
            'roles' => Rol::query()->whereNotNull('rol_padre_id')->orderBy('nombre')->get(['id', 'nombre']),
            'severidades' => ReglaAlertaVersion::SEVERIDADES,
            'comparadores' => ReglaAlertaVersion::COMPARADORES,
            'ventanas' => ReglaAlertaVersion::VENTANAS,
        ];
    }

    /** @return array<string, mixed> */
    private function validarRegla(Request $peticion, ?int $id = null): array
    {
        return $peticion->validate([
            'nombre' => ['required', 'string', 'max:180',
                Rule::unique('reglas_alerta', 'nombre')->ignore($id)->whereNull('deleted_at')],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'categoria_id' => ['required', 'integer', Rule::exists('categorias_senal', 'id')->whereNull('deleted_at')],

            /*
             * La métrica decide el proveedor, así que el proveedor NO se
             * captura: derivado, no se pueden separar. Capturado, alguien
             * elegiría «asistencia» con una métrica académica y la regla se
             * guardaría sin poderse calcular jamás.
             */
            'metrica' => ['required', 'string', Rule::in(CatalogoMetricas::claves())],

            'campus_id' => ['nullable', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'nivel_estudios_id' => ['nullable', 'integer'],
            'programa_academico_id' => ['nullable', 'integer', Rule::exists('programas_academicos', 'id')->whereNull('deleted_at')],
            'plan_id' => ['nullable', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')->whereNull('deleted_at')],
            'situacion_alumno_id' => ['nullable', 'integer', Rule::exists('situaciones_alumno', 'id')->whereNull('deleted_at')],
            'modalidad' => ['nullable', 'string', 'max:50'],
            'generacion_desde' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'generacion_hasta' => ['nullable', 'integer', 'min:1900', 'max:2200', 'gte:generacion_desde'],
            'asignatura_id' => ['nullable', 'integer', Rule::exists('asignaturas', 'id')->whereNull('deleted_at')],
            'notas' => ['nullable', 'string', 'max:2000'],
        ], [
            'generacion_hasta.gte' => 'La generación final no puede ser anterior a la inicial.',
            'metrica.in' => 'Esa métrica no existe: elige una de las que el sistema sabe calcular.',
        ]);
    }

    /**
     * @param  string|null  $metrica  la de la regla, para comprobar el umbral
     * @return array<string, mixed>
     */
    private function validarVersion(Request $peticion, ?string $metrica): array
    {
        $datos = $peticion->validate([
            'vigente_desde' => ['required', 'date'],
            'comparador' => ['required', Rule::in(ReglaAlertaVersion::COMPARADORES)],
            'umbral' => ['nullable', 'numeric'],
            'umbral_fuente' => ['required', Rule::in([ReglaAlertaVersion::FUENTE_FIJA, ReglaAlertaVersion::FUENTE_PLAN])],
            'ventana_tipo' => ['required', Rule::in(ReglaAlertaVersion::VENTANAS)],
            'ventana_valor' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'cobertura_minima' => ['required', 'integer', 'min:0', 'max:9999'],
            'severidad' => ['required', Rule::in(ReglaAlertaVersion::SEVERIDADES)],
            'peso' => ['required', 'integer', 'min:1', 'max:10'],
            'frecuencia' => ['required', Rule::in(['diaria', 'semanal', 'por_evento'])],
            'cooldown_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'sla_horas' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'responsable_rol_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'avisa_al_alumno' => ['boolean'],
            'avisa_a_la_escuela' => ['boolean'],
            'plantilla_aviso' => ['nullable', 'string', 'max:500'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         * La UNIDAD la pone el sistema, así que escribirla en la plantilla la
         * duplica: «{valor} %» sale como «63 % %». Y peor, invita a escribir la
         * que no es —«va en {valor} %» sobre una regla que cuenta días—, que es
         * como el aviso acaba diciendo «llevas 15 % de atraso». Se rehúsa aquí,
         * con quien la redacta delante.
         */
        $deMas = app(PlantillaDeAviso::class)->unidadDeMas((string) ($datos['plantilla_aviso'] ?? ''));

        AvisoParaElUsuario::si(
            $deMas !== null,
            422,
            'La plantilla escribe «'.$deMas.'» detrás de la marca, y esa unidad la pone el sistema '
            .'a partir de lo que la regla mide: quítala y deja sólo {valor} o {umbral}. Así el aviso '
            .'dice «63 %» en una regla de porcentaje y «15 días» en una de atraso, sin que haya que '
            .'acordarse de cambiarlo al cambiar la métrica.',
        );

        /*
         * ── Las cuatro guardas contra una regla que no mediría nada ────────
         * Ninguna falla sola: la regla se guardaría, no se dispararía nunca, y
         * quien la escribió creería que sí. Es la clase de defecto que sólo se
         * descubre preguntando «¿por qué no hay alertas?» seis semanas después.
         */
        AvisoParaElUsuario::si(
            $datos['umbral_fuente'] === ReglaAlertaVersion::FUENTE_FIJA && ($datos['umbral'] ?? null) === null,
            422,
            'Falta el umbral: sin número no hay con qué comparar y la regla no se dispararía nunca.',
        );

        AvisoParaElUsuario::si(
            $datos['ventana_tipo'] === 'ultimos_dias' && ($datos['ventana_valor'] ?? null) === null,
            422,
            'Una ventana de «últimos N días» necesita el número de días.',
        );

        // El umbral del plan sólo lo sabe leer lo académico: en asistencia o en
        // finanzas no hay ningún número del plan con el que comparar.
        AvisoParaElUsuario::si(
            $datos['umbral_fuente'] === ReglaAlertaVersion::FUENTE_PLAN
                && $metrica !== null
                && (CatalogoMetricas::de($metrica)['proveedor'] ?? null) !== 'academico',
            422,
            'El umbral del plan sólo se puede leer en las métricas académicas: en las demás no hay '
            .'ningún número del plan con el que comparar.',
        );

        /*
         * Y la cuarta AVISA en vez de rehusar: hay reglas legítimas al revés
         * —«promedio por encima de X» para una beca de excelencia—, así que
         * bloquearlas sería impedir un caso real por proteger de un error de
         * captura. Se guarda, y la pantalla lo dice.
         */
        $datos['avisa_al_alumno'] = $peticion->boolean('avisa_al_alumno');
        $datos['avisa_a_la_escuela'] = $peticion->boolean('avisa_a_la_escuela');

        return $datos;
    }

    private function tieneAlertas(ReglaAlerta $regla): bool
    {
        if (! Schema::hasTable('alertas')) {
            return false;
        }

        return DB::table('alertas')->where('regla_id', $regla->id)->exists();
    }
}
