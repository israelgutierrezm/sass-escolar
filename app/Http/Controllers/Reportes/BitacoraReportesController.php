<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Models\Reportes\EjecucionReporte;
use App\Reportes\FiltroReporte;
use App\Reportes\RegistroReportes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quién corrió cada reporte, con qué filtros y cuánto se llevó.
 *
 * ── Por qué es una pantalla y no una consulta a la base ──────────────────
 * `ejecuciones_reporte` lleva desde la primera rebanada escribiéndose sin que
 * nadie la pueda mirar. Dos preguntas la esperaban:
 *
 *  1. **«¿Quién sacó la lista con las CURP?»** En un sistema escolar se acaba
 *     haciendo, y hasta hoy sólo la contestaba quien supiera entrar a MySQL.
 *  2. **«¿Qué se usa de verdad?»** Es el insumo con el que se decide si vale la
 *     pena construir el constructor de reportes que pidió el cliente. Su
 *     criterio de entrada está escrito en `docs/plan-reportes.md` §7 y se mide
 *     con esta tabla: sin pantalla, nadie lo iba a medir.
 *
 * ── Lo que esta pantalla NO enseña ───────────────────────────────────────
 * Las FILAS de ningún reporte. La bitácora guarda lo que se PIDIÓ —el reporte,
 * los filtros, las columnas— y nunca lo que salió, así que `auditar-reportes`
 * no es una puerta trasera a los datos: quien audita ve que alguien exportó la
 * cartera del campus norte, no la cartera.
 *
 * Por eso mismo es un permiso APARTE de `ver-reportes` y no una sección suya:
 * quien saca reportes todos los días no tiene por qué ver lo que sacan los
 * demás, y quien vigila no necesariamente saca ninguno.
 */
class BitacoraReportesController extends Controller
{
    /** Cuántas ejecuciones caben en una página. */
    private const POR_PAGINA = 40;

    public function __construct(private readonly RegistroReportes $registro) {}

    public function index(Request $peticion): Response
    {
        $filtros = [
            'reporte' => $peticion->string('reporte')->toString() ?: null,
            'formato' => $peticion->string('formato')->toString() ?: null,
            'persona' => $peticion->string('persona')->toString() ?: null,
            'desde' => $peticion->date('desde')?->toDateString(),
            'hasta' => $peticion->date('hasta')?->toDateString(),
        ];

        $pagina = $this->filtrada($filtros)
            ->with('persona:id,nombre,primer_apellido,segundo_apellido')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return Inertia::render('Reportes/Bitacora', [
            'ejecuciones' => $pagina->through(fn (EjecucionReporte $e) => $this->renglon($e)),
            'filtros' => $filtros,
            'reportes' => $this->reportesQueHanCorrido(),
            'formatos' => $this->formatosQueHanCorrido(),
            'resumen' => $this->resumen($filtros),
        ]);
    }

    /**
     * Los filtros, en UN solo sitio.
     *
     * Los usan el listado y el resumen, y por eso están aquí: **los filtros
     * tienen que mover las DOS cifras**. La primera versión dejaba el filtro de
     * persona fuera del resumen —porque va por relación y los otros no— y se vio
     * al mirar la pantalla: buscando un nombre que no existe, la tabla decía
     * «ninguna ejecución» y el recuadro de arriba seguía diciendo 119.
     *
     * Es el mismo defecto que este proyecto ya se cobró en el tablero de la
     * bolsa de trabajo: dos universos pegados en la misma pantalla, y quien lee
     * eso deja de creerle al tablero entero.
     *
     * @param  array<string, mixed>  $filtros
     */
    private function filtrada(array $filtros): Builder
    {
        return EjecucionReporte::query()
            ->when($filtros['reporte'], fn (Builder $q, $v) => $q->where('reporte', $v))
            ->when($filtros['formato'], fn (Builder $q, $v) => $q->where('formato', $v))
            /*
             * Por NOMBRE y no por id: quien audita busca «Gutiérrez», no el 274.
             * Va contra la relación para no unir `personas` al listado, que se
             * ordena por fecha y ya usa su índice.
             */
            ->when($filtros['persona'], fn (Builder $q, $v) => $q->whereHas(
                'persona',
                fn (Builder $p) => $p->whereRaw(
                    'concat_ws(" ", nombre, primer_apellido, segundo_apellido) like ?',
                    ['%'.$v.'%'],
                ),
            ))
            ->when($filtros['desde'], fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['hasta'], fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v));
    }

    /**
     * Un renglón de la bitácora.
     *
     * El TÍTULO sale del registro, pero la CLAVE se conserva y se enseña cuando
     * el reporte ya no existe: un reporte retirado deja sus ejecuciones atrás, y
     * un renglón que dijera «—» no se podría investigar.
     *
     * @return array<string, mixed>
     */
    private function renglon(EjecucionReporte $ejecucion): array
    {
        $definicion = $this->registro->todos()[$ejecucion->reporte] ?? null;

        return [
            'id' => $ejecucion->id,
            'momento' => $ejecucion->created_at?->toIso8601String(),
            'reporte' => $ejecucion->reporte,
            'titulo' => $definicion?->titulo(),
            'persona' => $ejecucion->persona?->nombreCompleto(),
            'formato' => $ejecucion->formato,
            'filas' => $ejecucion->filas,
            'milisegundos' => $ejecucion->milisegundos,
            'filtros' => $this->filtrosLegibles($ejecucion),
            'columnas' => count($ejecucion->columnas ?? []),
            'omitidas' => $this->etiquetasOmitidas($ejecucion),
        ];
    }

    /**
     * Los filtros, con la ETIQUETA de cada uno y no su clave.
     *
     * «ciclo_id: [331]» no se puede auditar; «Ciclo de la carga: 331» sí. Se
     * traduce contra la fuente cuando el reporte todavía existe, y se deja crudo
     * cuando no: enseñar la clave es peor que la etiqueta, pero perder el filtro
     * es peor que la clave.
     *
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    private function filtrosLegibles(EjecucionReporte $ejecucion): array
    {
        $catalogo = $this->catalogoDeFiltros($ejecucion->reporte);
        $legibles = [];

        foreach ($ejecucion->filtros ?? [] as $clave => $valor) {
            $legibles[] = [
                'etiqueta' => $catalogo[$clave]->etiqueta ?? $clave,
                'valor' => $this->comoTexto($valor),
            ];
        }

        return $legibles;
    }

    /**
     * Las columnas omitidas, con su etiqueta.
     *
     * Es la mitad que explica por qué dos corridas del mismo reporte trajeron
     * distinto número de columnas — y de paso, quién intentó llevarse un dato
     * sensible sin poder.
     *
     * @return array<int, string>
     */
    private function etiquetasOmitidas(EjecucionReporte $ejecucion): array
    {
        $definicion = $this->registro->todos()[$ejecucion->reporte] ?? null;

        if ($definicion === null) {
            return $ejecucion->columnas_omitidas ?? [];
        }

        $catalogo = $this->registro->fuente($definicion->fuente())->columnas();

        return array_values(array_map(
            fn (string $c) => $catalogo[$c]->etiqueta ?? $c,
            $ejecucion->columnas_omitidas ?? [],
        ));
    }

    /** @return array<string, FiltroReporte> */
    private function catalogoDeFiltros(string $reporte): array
    {
        $definicion = $this->registro->todos()[$reporte] ?? null;

        return $definicion === null
            ? []
            : $this->registro->fuente($definicion->fuente())->filtros();
    }

    /** Un valor de filtro tal como se pueda leer. */
    private function comoTexto(mixed $valor): string
    {
        if (is_array($valor)) {
            return implode(', ', array_map(fn ($v) => $this->comoTexto($v), $valor));
        }

        if (is_bool($valor)) {
            return $valor ? 'sí' : 'no';
        }

        return (string) $valor;
    }

    /**
     * De qué reportes hay ejecuciones, para el desplegable del filtro.
     *
     * De la BITÁCORA y no del registro: ofrecer los 34 haría que casi todos
     * devolvieran cero, y filtrar por uno que nadie ha corrido no informa.
     *
     * @return array<int, array{clave: string, titulo: string}>
     */
    private function reportesQueHanCorrido(): array
    {
        $registro = $this->registro->todos();

        return EjecucionReporte::query()
            ->distinct()
            ->orderBy('reporte')
            ->pluck('reporte')
            ->map(fn (string $clave) => [
                'clave' => $clave,
                'titulo' => isset($registro[$clave])
                    ? $registro[$clave]->titulo()
                    : $clave.' (retirado)',
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function formatosQueHanCorrido(): array
    {
        return EjecucionReporte::query()
            ->distinct()
            ->orderBy('formato')
            ->pluck('formato')
            ->all();
    }

    /**
     * El resumen de arriba, sobre LO FILTRADO y no sobre la tabla entera.
     *
     * Es la misma lección que dejó escrita la cartera: un total sacado de otra
     * cosa que lo que se está mirando es el número más visible de la pantalla y
     * el más fácil de dar por bueno.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function resumen(array $filtros): array
    {
        // Los MISMOS filtros del listado, sin excepción: ver `filtrada()`.
        $base = fn () => $this->filtrada($filtros);

        return [
            'ejecuciones' => $base()->count(),
            'personas' => $base()->distinct()->count('persona_id'),
            /*
             * Las DESCARGAS aparte, porque son la pregunta cara: un archivo sale
             * de la escuela y se reenvía; una pantalla se mira y se cierra.
             */
            'descargas' => $base()->where('formato', '!=', 'pantalla')->count(),
            'filas_descargadas' => (int) $base()->where('formato', '!=', 'pantalla')->sum('filas'),
            'mas_lento' => $base()->max('milisegundos'),
        ];
    }
}
