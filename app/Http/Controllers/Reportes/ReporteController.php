<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Models\Reportes\ReporteFavorito;
use App\Models\Reportes\VistaReporte;
use App\Reportes\ColumnaReporte;
use App\Reportes\Ejecutor;
use App\Reportes\FiltroReporte;
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
     */
    public function descargar(Request $peticion, string $clave, string $formato): StreamedResponse
    {
        abort_unless(in_array($formato, ['xlsx', 'csv'], true), 404);

        $exportacion = $this->ejecutor->paraExportar($peticion->user(), $clave, [
            'columnas' => $peticion->input('columnas'),
            'filtros' => $peticion->input('filtros', []),
            'orden_por' => $peticion->input('orden_por'),
            'orden_dir' => $peticion->input('orden_dir'),
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
        $resultado = $this->ejecutor->ejecutar($usuario, $clave, [
            'columnas' => $peticion->input('columnas') ?? $vista?->columnas,
            'filtros' => $peticion->input('filtros') ?? $vista?->filtros ?? [],
            'orden_por' => $peticion->input('orden_por') ?? $vista?->orden_por,
            'orden_dir' => $peticion->input('orden_dir') ?? $vista?->orden_dir,
            'por_pagina' => $peticion->input('por_pagina', 50),
        ]);

        $fuente = $resultado->fuente;

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
                'alineacion' => $c->alineacion(),
                'ordenable' => $c->ordenable,
            ], $resultado->columnas),
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
            'aplicados' => (object) $peticion->input('filtros', []),
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
