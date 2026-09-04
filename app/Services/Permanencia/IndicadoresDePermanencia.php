<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\CategoriaSenal;
use App\Models\Permanencia\CorridaEvaluacion;
use App\Models\Permanencia\EstadoCaso;
use App\Models\Permanencia\ReglaAlerta;
use App\Permanencia\RegistroProveedores;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras agregadas del módulo, y lo que hace falta para no leerlas mal.
 *
 * ── LA COBERTURA VA CON LA CIFRA, siempre ─────────────────────────────────
 * Es lo más importante de este servicio. **El sesgo dominante de este módulo no
 * es demográfico: es de CAPTURA.** Un plantel que no pasa lista no produce
 * señales de asistencia, y leído sin cuidado el tablero dice que es el que mejor
 * va. Un coordinador que ve «0 señales de asistencia» tiene que poder distinguir
 * «ahí nadie falta» de «ahí nadie pasa lista», y ésa es la diferencia entre un
 * tablero útil y uno que induce a error.
 *
 * ── El TAMAÑO MÍNIMO DE GRUPO suprime celdas ──────────────────────────────
 * Un desglose por generación y programa deja celdas de dos alumnos, y eso
 * identifica a personas concretas: en una escuela chica, «2 casos de la
 * generación 2024 de Enfermería» es señalar con el dedo. Bajo el mínimo se dice
 * «muy pocos para desglosar» y no el número.
 *
 * **Y es una CONSTANTE, no un ajuste**, al revés de lo que manda la regla
 * general del proyecto. La razón es la misma que en `ResultadosDeEncuesta`: es
 * un piso de privacidad, no una preferencia de operación, y un ajuste invita a
 * bajarlo para ver el dato — que es exactamente lo que existe para impedir.
 * Pública para poder ejercitarla sin inventar trescientos alumnos.
 *
 * ── Y NADA de esto identifica a nadie ─────────────────────────────────────
 * Son conteos. Los nombres están en la bandeja y en los casos, cada uno con su
 * permiso y su alcance por campus; este servicio no los devuelve nunca.
 */
class IndicadoresDePermanencia
{
    /** Bajo este número, una celda no se enseña. */
    public const MINIMO_POR_GRUPO = 5;

    /** Sobre esta proporción de descartes, una regla está mal calibrada. */
    public const DESCARTE_QUE_PREOCUPA = 0.6;

    /** La ventana por omisión de los indicadores con historia. */
    public const DIAS = 90;

    public function __construct(private readonly AlcanceDeCasos $alcance) {}

    /**
     * ── Recibe al USUARIO y no una lista de campus ────────────────────────
     * Para que el lado de los CASOS pueda reusar `AlcanceDeCasos`, que es la
     * única definición de hasta dónde alcanza alguien en un caso. Con la regla
     * escrita dos veces, el tablero acabaría enseñando lo que la bandeja
     * esconde — y el día que una cambie, nadie sabría cuál vale.
     *
     * @return array<string, mixed>
     */
    public function tablero(?Usuario $usuario, int $dias = self::DIAS, ?CarbonImmutable $ahora = null): array
    {
        $momento = $ahora ?? CarbonImmutable::now();
        $desde = $momento->subDays($dias);

        /* `campusVisibles()` devuelve null con alcance global. null no es lo mismo que un arreglo vacío. */
        $campus = $usuario?->campusVisibles();

        return [
            'ventana' => ['dias' => $dias, 'desde' => $desde->toDateString()],
            'cobertura' => $this->cobertura(),
            'senales' => $this->senales($campus, $desde),
            'calibracion' => $this->calibracion($campus, $desde),
            'casos' => $this->casos($usuario, $desde, $momento),
            'desenlaces' => $this->desenlaces($usuario, $desde),
            'por_campus' => $this->porCampus($usuario, $desde),
            'minimo_por_grupo' => self::MINIMO_POR_GRUPO,
        ];
    }

    /**
     * Con qué se está midiendo, y qué NO se pudo medir.
     *
     * ── `sin_datos` es la cifra que salva el tablero ──────────────────────
     * El motor devuelve TRES resultados —dispara, no dispara y sin datos— y el
     * tercero es el que dice cuánto de la escuela no se está mirando. Sin él,
     * una cola vacía se lee como ausencia de riesgo.
     *
     * @return array<string, mixed>
     */
    private function cobertura(): array
    {
        $corrida = CorridaEvaluacion::query()->latest('iniciada_en')->first();

        $mediciones = $corrida === null
            ? 0
            : (int) $corrida->matriculas_evaluadas * (int) $corrida->reglas_evaluadas;

        return [
            'corrio_en' => $corrida?->iniciada_en?->format('Y-m-d H:i'),
            'alumnos' => (int) ($corrida?->matriculas_evaluadas ?? 0),
            'reglas' => (int) ($corrida?->reglas_evaluadas ?? 0),
            'sin_datos' => (int) ($corrida?->sin_datos ?? 0),
            /*
             * La PROPORCIÓN, no sólo el número: «40 sin datos» no dice nada sin
             * saber sobre cuántas mediciones. Y en null cuando no hay ninguna,
             * porque un 0 % ahí afirmaría que todo se midió.
             */
            'proporcion_sin_datos' => $mediciones === 0
                ? null
                : round(((int) $corrida->sin_datos) * 100 / $mediciones, 1),
            /*
             * Y lo que cada proveedor DECLARA sobre su fuente. Es lo que impide
             * leer un 60 % de asistencia como si fuera del semestre entero.
             */
            'fuentes' => collect(app(RegistroProveedores::class)->todos())
                ->map(fn ($p) => [
                    'clave' => $p->clave(),
                    'titulo' => $p->titulo(),
                    'calidad' => $p->calidad(),
                    'ultima_actualizacion' => $p->ultimaActualizacion(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Cuántas señales, en qué estado y de qué categoría.
     *
     * @param  array<int>|null  $campus
     * @return array<string, mixed>
     */
    private function senales(?array $campus, CarbonImmutable $desde): array
    {
        $base = fn () => $this->acotarSenales(Alerta::query(), $campus);

        return [
            'por_revisar' => (int) $base()->abiertas()->where('estado_triage', Alerta::NUEVA)->count(),
            'validadas_abiertas' => (int) $base()->abiertas()
                ->where('estado_triage', Alerta::VALIDADA)->count(),
            'levantadas' => (int) $base()->where('primera_vez_en', '>=', $desde)->count(),
            'resueltas' => (int) $base()->where('estado_senal', Alerta::RESUELTA)
                ->where('cerrada_en', '>=', $desde)->count(),
            /*
             * Las OBSOLETAS aparte de las resueltas, y no es un matiz: resuelta
             * es «la situación mejoró» y obsoleta es «se dejó de vigilar». Con
             * las dos juntas, apagar una regla se leería como que doscientos
             * alumnos se recuperaron, y ese número acabaría en un informe.
             */
            'obsoletas' => (int) $base()->where('estado_senal', Alerta::OBSOLETA)
                ->where('cerrada_en', '>=', $desde)->count(),
            'por_categoria' => $this->porCategoria($campus),
        ];
    }

    /**
     * La cola por categoría, con el mínimo aplicado.
     *
     * @param  array<int>|null  $campus
     * @return array<int, array<string, mixed>>
     */
    private function porCategoria(?array $campus): array
    {
        $conteos = $this->acotarSenales(Alerta::query(), $campus)
            ->abiertas()
            ->selectRaw('categoria_id, count(*) as c')
            ->groupBy('categoria_id')
            ->pluck('c', 'categoria_id');

        return CategoriaSenal::query()->activas()->get()
            ->map(fn (CategoriaSenal $cat) => [
                'nombre' => $cat->nombre,
                'color' => $cat->color,
                'sensible' => (bool) $cat->sensible,
                'total' => (int) ($conteos[$cat->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * La CALIBRACIÓN: qué reglas se descartan casi siempre.
     *
     * ── Es el indicador de primera línea del módulo ───────────────────────
     * Una regla cuyas señales se descartan el 80 % de las veces le está haciendo
     * perder el tiempo a quien revisa, y a la tercera semana nadie mira la
     * bandeja — con lo cual las buenas se pierden también. Por eso el motivo del
     * descarte sale de un catálogo: con trescientas frases libres no hay nada
     * que contar.
     *
     * **Sólo se opina de las reglas con suficientes revisadas.** Con tres
     * señales, «66 % de descarte» son dos casos y no significa nada; peor, en
     * una escuela chica esas dos son personas identificables.
     *
     * @param  array<int>|null  $campus
     * @return array<int, array<string, mixed>>
     */
    private function calibracion(?array $campus, CarbonImmutable $desde): array
    {
        $filas = $this->acotarSenales(Alerta::query(), $campus)
            ->whereIn('estado_triage', [Alerta::VALIDADA, Alerta::DESCARTADA])
            ->where('revisada_en', '>=', $desde)
            ->selectRaw('regla_id, count(*) as revisadas, '
                .'sum(case when estado_triage = ? then 1 else 0 end) as descartadas',
                [Alerta::DESCARTADA])
            ->groupBy('regla_id')
            ->get();

        $nombres = ReglaAlerta::query()->whereIn('id', $filas->pluck('regla_id'))
            ->pluck('nombre', 'id');

        return $filas
            ->map(function ($fila) use ($nombres) {
                $revisadas = (int) $fila->revisadas;
                $descartadas = (int) $fila->descartadas;

                $suficientes = $revisadas >= self::MINIMO_POR_GRUPO;

                return [
                    'regla' => $nombres[$fila->regla_id] ?? 'Ya no existe',
                    'revisadas' => $revisadas,
                    /*
                     * Bajo el mínimo NO se enseña la proporción, y se dice por
                     * qué: un porcentaje sobre tres casos parece un dato y no lo
                     * es, y en una escuela chica esos tres son personas.
                     */
                    'descartadas' => $suficientes ? $descartadas : null,
                    'proporcion' => $suficientes ? round($descartadas * 100 / $revisadas, 1) : null,
                    'suficientes' => $suficientes,
                    'preocupa' => $suficientes
                        && $descartadas / $revisadas >= self::DESCARTE_QUE_PREOCUPA,
                ];
            })
            ->sortByDesc(fn (array $f) => $f['proporcion'] ?? -1)
            ->values()
            ->all();
    }

    /**
     * El acompañamiento: cuántos, cuánto tardan y cuánto se atascan.
     *
     * @return array<string, mixed>
     */
    private function casos(?Usuario $usuario, CarbonImmutable $desde, CarbonImmutable $momento): array
    {
        $base = fn () => $this->alcance->acotar(CasoPermanencia::query(), $usuario);

        $tiempos = $base()
            ->whereNotNull('primer_contacto_en')
            ->where('abierto_en', '>=', $desde)
            ->selectRaw('avg(timestampdiff(hour, abierto_en, primer_contacto_en)) as horas, count(*) as c')
            ->first();

        $duracion = $base()
            ->whereNotNull('cerrado_en')
            ->where('cerrado_en', '>=', $desde)
            ->selectRaw('avg(datediff(cerrado_en, abierto_en)) as dias, count(*) as c')
            ->first();

        return [
            'abiertos' => (int) $base()->abiertos()->count(),
            'sin_asignar' => (int) $base()->sinAsignar()->count(),
            'fuera_de_plazo' => (int) $base()->slaVencido($momento->toDateTimeString())->count(),
            'abiertos_en_ventana' => (int) $base()->where('abierto_en', '>=', $desde)->count(),
            'cerrados_en_ventana' => (int) $base()->where('cerrado_en', '>=', $desde)->count(),
            /*
             * Los promedios se dicen CON su denominador. Un «12 h de primer
             * contacto» calculado sobre dos casos no es el tiempo de la escuela,
             * y quien lo lea sin el conteo se lo va a creer.
             */
            'horas_primer_contacto' => $tiempos?->horas === null ? null : round((float) $tiempos->horas, 1),
            'casos_con_contacto' => (int) ($tiempos?->c ?? 0),
            'dias_para_cerrar' => $duracion?->dias === null ? null : round((float) $duracion->dias, 1),
            'casos_cerrados' => (int) ($duracion?->c ?? 0),
            'por_estado' => $base()->abiertos()
                ->selectRaw('estado, count(*) as c')->groupBy('estado')->pluck('c', 'estado')
                ->mapWithKeys(fn ($c, $e) => [EstadoCaso::from($e)->etiqueta() => (int) $c])
                ->all(),
        ];
    }

    /**
     * EFECTIVIDAD y RECURRENCIA: en qué terminó, y qué volvió.
     *
     * ── Lo declarado y lo medido, juntos ──────────────────────────────────
     * `cuenta_como_exito` es lo que declaró quien cerró; «la señal mejoró» es lo
     * que de verdad pasó. Con una sola cifra nadie puede saber si el indicador
     * dice algo — y la diferencia entre las dos es información, no un error.
     *
     * @return array<string, mixed>
     */
    private function desenlaces(?Usuario $usuario, CarbonImmutable $desde): array
    {
        $cerrados = $this->alcance->acotar(CasoPermanencia::query(), $usuario)
            ->where('estado', EstadoCaso::Cerrado->value)
            ->where('cerrado_en', '>=', $desde);

        $total = (int) (clone $cerrados)->count();

        $porBandera = (clone $cerrados)
            ->leftJoin('motivos_cierre_caso', 'motivos_cierre_caso.id', '=', 'casos_permanencia.motivo_cierre_id')
            ->selectRaw('motivos_cierre_caso.cuenta_como_exito as bandera, count(*) as c')
            ->groupBy('motivos_cierre_caso.cuenta_como_exito')
            ->pluck('c', 'bandera');

        /*
         * Lo MEDIDO: de los cerrados, en cuántos TODAS sus señales dejaron de
         * estar activas. Se cuenta con `whereDoesntHave` sobre las activas, que
         * es la forma de decir «ninguna sigue abierta» sin traerlas todas.
         */
        $conSenalResuelta = (int) (clone $cerrados)
            ->whereHas('alertas')
            ->whereDoesntHave('alertas', fn (Builder $a) => $a->where('estado_senal', Alerta::ACTIVA))
            ->count();

        $conSenales = (int) (clone $cerrados)->whereHas('alertas')->count();

        return [
            'cerrados' => $total,
            'exito' => (int) ($porBandera[1] ?? 0),
            'sin_exito' => (int) ($porBandera[0] ?? 0),
            // La bandera en NULL es «ni una cosa ni otra»: un traslado, un caso
            // abierto por error. Contarlo como fracaso castigaría a quien
            // atendió bien algo que dejó de ser suyo.
            'ni_uno_ni_otro' => $total - (int) ($porBandera[1] ?? 0) - (int) ($porBandera[0] ?? 0),
            'senal_resuelta' => $conSenalResuelta,
            'cerrados_con_senal' => $conSenales,
            'reaperturas' => (int) $this->alcance->acotar(CasoPermanencia::query(), $usuario)
                ->whereNotNull('caso_origen_id')
                ->where('abierto_en', '>=', $desde)
                ->count(),
        ];
    }

    /**
     * El desglose por campus, con el mínimo aplicado.
     *
     * ── Y aquí es donde el mínimo importa de verdad ───────────────────────
     * Un plantel con tres casos abiertos es un plantel donde esos tres son
     * identificables por quien conozca la escuela. Se dice que hay actividad y
     * no cuánta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function porCampus(?Usuario $usuario, CarbonImmutable $desde): array
    {
        $casos = $this->alcance->acotar(CasoPermanencia::query(), $usuario)
            ->where('abierto_en', '>=', $desde)
            ->selectRaw('campus_id, count(*) as c')
            ->groupBy('campus_id')
            ->get();

        $nombres = DB::table('campus')->whereNull('deleted_at')->pluck('nombre', 'id');

        return $casos
            ->map(function ($fila) use ($nombres) {
                $total = (int) $fila->c;
                $suficientes = $total >= self::MINIMO_POR_GRUPO;

                return [
                    'campus' => $fila->campus_id === null
                        ? 'Sin campus asignado'
                        : ($nombres[$fila->campus_id] ?? 'Ya no existe'),
                    'total' => $suficientes ? $total : null,
                    'suficientes' => $suficientes,
                ];
            })
            ->sortByDesc(fn (array $f) => $f['total'] ?? -1)
            ->values()
            ->all();
    }

    /**
     * Las señales que este usuario alcanza.
     *
     * Por la OFERTA de la matrícula, igual que la bandeja: `alertas` no tiene
     * `campus_id`. Un tablero sin recortar pondría la cifra de la escuela entera
     * delante de quien coordina un plantel — el defecto que el motor de reportes
     * ya documentó con los totales.
     *
     * @param  array<int>|null  $campus
     */
    private function acotarSenales(Builder $consulta, ?array $campus): Builder
    {
        return $campus === null
            ? $consulta
            : $consulta->whereHas('matricula.oferta', fn (Builder $o) => $o->whereIn('campus_id', $campus));
    }
}
