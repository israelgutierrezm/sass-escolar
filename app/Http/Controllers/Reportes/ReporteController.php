<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\Controller;
use App\Models\Identidad\Usuario;
use App\Models\Reportes\ReporteFavorito;
use App\Models\Reportes\VistaReporte;
use App\Reportes\ColumnaReporte;
use App\Reportes\DimensionReporte;
use App\Reportes\Ejecutor;
use App\Reportes\FiltroReporte;
use App\Reportes\FuenteAgrupable;
use App\Reportes\RegistroReportes;
use App\Reportes\Salida\ExportadorCsv;
use App\Reportes\Salida\ExportadorXlsx;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La sección de Reportes.
 *
 * El controlador NO conoce ningún reporte concreto: le pide al registro los que
 * esta persona puede ver y los entrega. Un reporte nuevo es una clase más y
 * aparece solo, igual que una tarjeta del panel.
 */
class ReporteController extends Controller
{
    public function __construct(
        private readonly RegistroReportes $registro,
        private readonly Ejecutor $ejecutor,
    ) {}

    /**
     * La descarga. Un solo camino: el mismo motor que la pantalla.
     *
     * El formato lo decide la URL y no un parametro suelto, para que la
     * direccion de una descarga sea enlazable igual que la del reporte.
     *
     * ── Y con la VISTA resuelta, igual que `ver()` ────────────────────────
     * No lo hacía, y era un desfase de verdad: `ver()` resuelve la vista
     * guardada y `descargar()` leía sólo la petición, así que con una vista
     * abierta la pantalla salía con TRES columnas y el Excel con las SEIS por
     * omisión —y con los filtros de la vista fuera—. Medido en su día:
     *
     *     PANTALLA columnas: 3  -> matricula, alumno, curp
     *     DESCARGA columnas: 6  -> matricula, alumno, programa académico, campus, ...
     *
     * Un archivo que trae MÁS de lo que la pantalla enseñó es la peor forma de
     * fallar: abre perfectamente y nadie lo revisa.
     *
     * La resolución es la MISMA función, no una copia: si divergieran, el
     * desfase volvería el día que una cambie.
     */
    public function descargar(Request $peticion, string $clave, string $formato): StreamedResponse
    {
        abort_unless(in_array($formato, ['xlsx', 'csv'], true), 404);

        $vista = $this->vistaAplicable($peticion, $clave, $peticion->user());

        $exportacion = $this->ejecutor->paraExportar($peticion->user(), $clave, [
            'columnas' => $peticion->input('columnas') ?? $vista?->columnas,
            'filtros' => $peticion->input('filtros') ?? $vista?->filtros ?? [],
            'orden_por' => $peticion->input('orden_por') ?? $vista?->orden_por,
            'orden_dir' => $peticion->input('orden_dir') ?? $vista?->orden_dir,
        ]);

        return $formato === 'csv'
            ? app(ExportadorCsv::class)->responder($exportacion)
            : app(ExportadorXlsx::class)->responder($exportacion);
    }

    /** El índice: qué reportes hay, agrupados por área. */
    public function index(Request $peticion): Response
    {
        return Inertia::render('Reportes/Index', [
            // Ya agrupados por el area CONFIGURADA: la escuela pudo renombrarla
            // o mover el reporte a otra, y el indice tiene que reflejarlo.
            'areas' => $this->registro->agrupadosPara($peticion->user()),
            'puedeOrganizar' => $peticion->user()->can('gestionar-areas-reporte'),
            // La bitacora es de quien AUDITA, que puede no ser quien organiza.
            'puedeAuditar' => $peticion->user()->can('auditar-reportes'),
            // Programar no exige permiso propio: quien puede ver un reporte
            // puede pedir que se lo manden. Lo que decide QUE sale es el rol
            // guardado, que el motor comprueba en cada corrida.
        ]);
    }

    /**
     * Un reporte, ejecutado.
     *
     * Las columnas y los filtros llegan por la URL, así que el resultado es
     * enlazable: quien arma la vista que necesita puede guardar la dirección o
     * mandársela a alguien, y sale igual.
     */
    public function ver(Request $peticion, string $clave): Response
    {
        $usuario = $peticion->user();

        $vista = $this->vistaAplicable($peticion, $clave, $usuario);

        /*
         * Lo de la URL gana sobre lo de la vista.
         *
         * Abrir una vista y despues tocar un filtro tiene que funcionar sin
         * tener que guardarla otra vez; si la vista ganara, la pantalla
         * ignoraria lo que la persona acaba de pedir.
         *
         * Y lo que se aplica son COLUMNAS y FILTROS: el permiso, la faceta y el
         * alcance por campus los vuelve a resolver el motor con los de QUIEN
         * ejecuta, no con los del dueno de la vista.
         */
        $peticionAlMotor = [
            'columnas' => $peticion->input('columnas') ?? $vista?->columnas,
            'filtros' => $peticion->input('filtros') ?? $vista?->filtros ?? [],
            'orden_por' => $peticion->input('orden_por') ?? $vista?->orden_por,
            'orden_dir' => $peticion->input('orden_dir') ?? $vista?->orden_dir,
            'por_pagina' => $peticion->input('por_pagina', 50),
        ];

        /*
         * ── Un filtro OBLIGATORIO que falta NO es un error de pantalla ────
         *
         * Tres reportes exigen uno —«Ciclo de la carga», «Ciclo» y «Asistencia
         * por debajo de»— y el motor se niega a correr sin él con un 422, que es
         * lo correcto: sin ciclo, «carga académica» barrería todos los ciclos y
         * la pregunta no está hecha.
         *
         * Pero ese 422 se lanzaba dentro de `preparar()`, o sea ANTES de que el
         * controlador pudiera dibujar nada: **pulsarlos desde el índice daba una
         * página de error**, y los tres eran inalcanzables desde la interfaz. La
         * única forma de abrirlos era escribir el filtro a mano en la URL.
         *
         * Así que se atrapa y se dibuja la pantalla SIN resultados, con el panel
         * de filtros y el motivo escrito: el reporte existe, sólo necesita que
         * le digas por dónde empezar. El 422 se conserva tal cual en la
         * DESCARGA, donde no hay nada que dibujar.
         */
        try {
            $resultado = $this->ejecutor->ejecutar($usuario, $clave, $peticionAlMotor);
            $faltaFiltro = null;
        } catch (AvisoParaElUsuario $aviso) {
            if ($aviso->getStatusCode() !== 422) {
                throw $aviso;
            }

            return $this->pantallaSinCorrer($usuario, $clave, $aviso->getMessage());
        }

        $fuente = $resultado->fuente;

        /*
         * El AGRUPADO va en la misma pantalla, con `?agrupar_por=`.
         *
         * Y no en una ruta propia, para que agrupar no pierda los filtros ni las
         * columnas que la persona acaba de elegir: es la MISMA pregunta mirada
         * de otra forma, no otra pantalla. Además la URL sigue siendo enlazable
         * y el enlace de descarga sigue llevándolo todo.
         *
         * Se calcula APARTE del plano y no en su lugar: la tabla de detalle se
         * sigue viendo debajo, que es lo que permite comprobar de un vistazo que
         * los subtotales suman.
         */
        $agrupadoPor = $peticion->string('agrupar_por')->toString() ?: null;
        $agrupado = null;

        if ($agrupadoPor !== null) {
            $agrupado = $this->ejecutor->agrupar($usuario, $clave, $agrupadoPor, [
                'columnas' => $peticion->input('columnas') ?? $vista?->columnas,
                'filtros' => $peticion->input('filtros') ?? $vista?->filtros ?? [],
            ]);
        }

        return Inertia::render('Reportes/Ver', [
            'reporte' => [
                'clave' => $resultado->reporte->clave(),
                'titulo' => $resultado->reporte->titulo(),
                'descripcion' => $resultado->reporte->descripcion(),
                // QUÉ ES UNA FILA, en palabras. Es lo que impide leer «28
                // alumnos» cuando son las 28 materias de una alumna.
                'grano' => $fuente->grano(),
            ],
            'columnas' => array_map(fn (ColumnaReporte $c) => [
                'clave' => $c->clave,
                'etiqueta' => $c->etiqueta,
                /*
                 * El TIPO viaja, no solo su consecuencia.
                 *
                 * Iba `alineacion` y no `tipo`, asi que la pantalla sabia hacia
                 * que lado pegar el numero y NO como escribirlo: el dinero salia
                 * «2750.00», «0» y «2750» en la misma fila --segun viniera del
                 * SELECT como cadena o de una closure como numero-- y una fecha
                 * salia «2026-08-05T06:00:00.000000Z». Ninguna de las dos cosas
                 * da error; se ven mirando la pantalla.
                 */
                'tipo' => $c->tipo->value,
                'alineacion' => $c->alineacion(),
                'ordenable' => $c->ordenable,
            ], $resultado->columnas),
            /*
             * El pie de la tabla, o null si ninguna columna pedida se totaliza.
             *
             * Sale de una consulta agregada aparte sobre el mismo builder ya
             * recortado, NO de la pagina: un total sacado de la pagina diria
             * «la cartera son 40 mil» cuando son los 40 mil de los 25 que se
             * estan viendo, y sin recortar filtraria la cifra de toda la
             * escuela debajo de una lista acotada a un plantel.
             */
            'totales' => $resultado->totales,
            // Por que columna se ordena de verdad, para que la cabecera lo
            // marque. Sale del MOTOR y no del request: lo que se pidio puede no
            // ser aplicable, y entonces la flecha estaria sobre una columna por
            // la que no se esta ordenando.
            'orden' => ['por' => $resultado->orden[0], 'dir' => $resultado->orden[1]],
            // Por que se PUEDE agrupar esta fuente. Vacio = no se puede, y
            // entonces el selector ni se dibuja: una fuente sin dimensiones no
            // ofrece el modo, que es fallar cerrado y es honesto.
            'dimensiones' => $fuente instanceof FuenteAgrupable
                ? array_values(array_map(fn (DimensionReporte $d) => [
                    'clave' => $d->clave,
                    'etiqueta' => $d->etiqueta,
                    'ayuda' => $d->ayuda,
                ], array_filter(
                    $fuente->dimensiones(),
                    fn (DimensionReporte $d) => $d->permisoExtra === null || $usuario->can($d->permisoExtra),
                )))
                : [],
            'agrupadoPor' => $agrupadoPor,
            'agrupado' => $agrupado === null ? null : [
                'dimension' => $agrupado->dimension->etiqueta,
                'grupos' => $agrupado->grupos,
                'medidas' => array_values(array_map(fn (ColumnaReporte $c) => [
                    'clave' => $c->clave,
                    'etiqueta' => $c->etiqueta,
                    'tipo' => $c->tipo->value,
                    'alineacion' => $c->alineacion(),
                ], $agrupado->medidas)),
                'filas' => $agrupado->filas(),
                'truncado' => $agrupado->truncado,
            ],
            // TODAS las de la fuente, para poder elegir cuáles se quieren.
            'disponibles' => array_values(array_map(fn (ColumnaReporte $c) => [
                'clave' => $c->clave,
                'etiqueta' => $c->etiqueta,
                'ayuda' => $c->ayuda,
                'sensible' => $c->sensible,
            ], $fuente->columnas())),
            'filtros' => array_values(array_map(fn (FiltroReporte $f) => [
                'clave' => $f->clave,
                'etiqueta' => $f->etiqueta,
                'tipo' => $f->tipo->value,
                'ayuda' => $f->ayuda,
                'opciones' => $f->opcionesPara($usuario),
            ], $fuente->filtros())),
            // Los fijos se ENSEÑAN pero no se pueden mover: es lo que hace que
            // el reporte conteste su pregunta y no otra.
            'filtrosFijos' => array_keys($resultado->reporte->filtrosFijos()),
            /*
             * Los filtros EFECTIVOS, no los de la petición.
             *
             * Iban los de la petición, así que con una vista abierta —o con los
             * filtros fijos de la definición— la pantalla mandaba un arreglo
             * vacío a los controles. Y como `aplicar()` reenvía lo que tiene en
             * pantalla, la primera pulsada en una cabecera recargaba SIN los
             * filtros de la vista: el reporte cambiaba de contenido al ordenarlo.
             */
            'aplicados' => (object) $resultado->filtros,
            // Null salvo que el reporte no se pueda correr todavía: ver
            // `pantallaSinCorrer()`.
            'faltaFiltro' => $faltaFiltro,
            'filas' => $resultado->filas,
            'paginacion' => [
                'total' => $resultado->total(),
                'links' => $resultado->paginador->linkCollection(),
                'from' => $resultado->paginador->firstItem(),
                'to' => $resultado->paginador->lastItem(),
            ],
            // Se DICE lo que se omitió, no se calla: quien lo lee creería que
            // el reporte no trae esa columna.
            'omitidas' => $resultado->etiquetasOmitidas(),
            'ms' => $resultado->milisegundos,

            // Las vistas que esta persona puede abrir: las suyas, las de su rol
            // ACTIVO y las de la escuela.
            'vistas' => VistaReporte::query()
                ->where('reporte', $clave)
                ->visiblesPara($usuario)
                ->orderByDesc('predeterminada')
                ->orderBy('nombre')
                ->get()
                ->map(fn (VistaReporte $v) => [
                    'id' => $v->id,
                    'nombre' => $v->nombre,
                    'descripcion' => $v->descripcion,
                    'predeterminada' => $v->predeterminada,
                    'deLaEscuela' => $v->persona_id === null,
                    'mia' => $v->persona_id === $usuario->persona_id,
                    'puedeEditar' => $v->laPuedeEditar($usuario),
                ])
                ->values(),
            'vistaActiva' => $vista?->id,
            'puedeCompartir' => $usuario->can('gestionar-areas-reporte'),
            'esFavorito' => ReporteFavorito::query()
                ->where('persona_id', $usuario->persona_id)
                ->where('reporte', $clave)
                ->exists(),
        ]);
    }

    /**
     * La vista que se abre: la pedida por la URL, o la predeterminada propia.
     *
     * Se busca DENTRO de las visibles para esta persona: pedir el id de una
     * vista ajena no la abre, cae a la predeterminada. Es la misma salvaguarda
     * que la credencial y el historial --la eleccion se busca en la lista
     * propia, asi que un id ajeno no encuentra pareja--.
     */
    /**
     * La pantalla de un reporte que todavía no se puede correr.
     *
     * Trae su catálogo de filtros y de columnas —para que se pueda elegir lo que
     * falta— y ni una fila. No es un error: es el reporte pidiendo lo que
     * necesita para contestar.
     */
    private function pantallaSinCorrer(Usuario $usuario, string $clave, string $motivo): Response
    {
        $definicion = $this->registro->definicion($clave);
        $fuente = $this->registro->fuente($definicion->fuente());

        return Inertia::render('Reportes/Ver', [
            'reporte' => [
                'clave' => $definicion->clave(),
                'titulo' => $definicion->titulo(),
                'descripcion' => $definicion->descripcion(),
                'grano' => $fuente->grano(),
            ],
            'columnas' => [],
            'disponibles' => array_values(array_map(fn (ColumnaReporte $c) => [
                'clave' => $c->clave,
                'etiqueta' => $c->etiqueta,
                'ayuda' => $c->ayuda,
                'sensible' => $c->sensible,
            ], $fuente->columnas())),
            'filtros' => array_values(array_map(fn (FiltroReporte $f) => [
                'clave' => $f->clave,
                'etiqueta' => $f->etiqueta,
                'tipo' => $f->tipo->value,
                'ayuda' => $f->ayuda,
                'opciones' => $f->opcionesPara($usuario),
            ], $fuente->filtros())),
            'filtrosFijos' => array_keys($definicion->filtrosFijos()),
            'aplicados' => (object) [],
            'faltaFiltro' => $motivo,
            'filas' => [],
            'paginacion' => ['total' => 0, 'links' => [], 'from' => null, 'to' => null],
            'omitidas' => [],
            'totales' => null,
            'ms' => 0,
            'vistas' => [],
            'vistaActiva' => null,
            'puedeCompartir' => $usuario->can('gestionar-areas-reporte'),
            'esFavorito' => false,
            'orden' => ['por' => null, 'dir' => 'asc'],
            'dimensiones' => [],
            'agrupadoPor' => null,
            'agrupado' => null,
        ]);
    }

    private function vistaAplicable(Request $peticion, string $clave, $usuario): ?VistaReporte
    {
        $visibles = VistaReporte::query()->where('reporte', $clave)->visiblesPara($usuario);

        $pedida = $peticion->integer('vista');

        if ($pedida > 0) {
            return (clone $visibles)->whereKey($pedida)->first()
                ?? $this->predeterminada($clave, $usuario);
        }

        // Sin vista pedida NI filtros en la URL se abre la predeterminada. Con
        // filtros en la URL no: quien llego con un enlace pidio algo concreto.
        return $peticion->hasAny(['filtros', 'columnas', 'orden_por'])
            ? null
            : $this->predeterminada($clave, $usuario);
    }

    private function predeterminada(string $clave, $usuario): ?VistaReporte
    {
        return VistaReporte::query()
            ->where('reporte', $clave)
            ->where('persona_id', $usuario->persona_id)
            ->where('predeterminada', true)
            ->first();
    }
}
