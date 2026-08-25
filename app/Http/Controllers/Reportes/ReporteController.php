<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reportes;

use App\Http\Controllers\Controller;
use App\Reportes\ColumnaReporte;
use App\Reportes\DefinicionReporte;
use App\Reportes\Ejecutor;
use App\Reportes\FiltroReporte;
use App\Reportes\RegistroReportes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    /** El índice: qué reportes hay, agrupados por área. */
    public function index(Request $peticion): Response
    {
        $reportes = $this->registro->para($peticion->user());

        return Inertia::render('Reportes/Index', [
            'reportes' => array_map(fn (DefinicionReporte $r) => [
                'clave' => $r->clave(),
                'titulo' => $r->titulo(),
                'descripcion' => $r->descripcion(),
                'area' => $r->areaSugerida(),
            ], $reportes),
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

        $resultado = $this->ejecutor->ejecutar($usuario, $clave, [
            'columnas' => $peticion->input('columnas'),
            'filtros' => $peticion->input('filtros', []),
            'orden_por' => $peticion->input('orden_por'),
            'orden_dir' => $peticion->input('orden_dir'),
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
        ]);
    }
}
