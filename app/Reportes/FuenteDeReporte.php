<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * De dónde salen las filas de un reporte.
 *
 * ── Fuente y reporte no son lo mismo ─────────────────────────────────────
 * Una FUENTE es «de dónde se saca», con sus columnas y sus filtros posibles.
 * Un REPORTE es un preset sobre ella: «Alumnos inscritos», «Bajas del ciclo» y
 * «Egresados por generación» son la MISMA fuente de matrículas con tres juegos
 * de filtros fijos. Sin esa separación harían falta cuarenta clases de consulta
 * casi iguales, y la que se corrigiera no arreglaría a las otras treinta y nueve.
 *
 * La consulta la escribe un PROGRAMADOR. Lo que la escuela configura es qué
 * columnas quiere, con qué filtros y en qué orden — nunca SQL.
 */
interface FuenteDeReporte
{
    /** Estable: la guardan las vistas, los favoritos y la bitácora. */
    public function clave(): string;

    public function titulo(): string;

    /**
     * QUÉ ES UNA FILA, en palabras, y se enseña en la pantalla.
     *
     * «Una fila es una matrícula» contra «una fila es una materia cursada» es la
     * diferencia entre leer «28 alumnos» y «28 materias de una alumna». Es el
     * dato que impide malinterpretar el total de un reporte.
     */
    public function grano(): string;

    /** UNO. Si dos oficios entran, se declara un derivado con `Gate::define`. */
    public function permiso(): string;

    /** El módulo apagable del que depende, ADEMÁS de `reportes`. */
    public function modulo(): ?string;

    /**
     * Las facetas que pueden ejecutarla.
     *
     * @return array<int, string>
     */
    public function facetas(): array;

    /** OBLIGATORIO y sin valor por omisión: ver `Recorte`. */
    public function recorte(): Recorte;

    /** @return array<string, ColumnaReporte> */
    public function columnas(): array;

    /** @return array<string, FiltroReporte> */
    public function filtros(): array;

    /**
     * El Builder ya armado, con su eager loading COMPLETO.
     *
     * El motor le aplica encima el recorte, los filtros y el orden. El eager
     * loading va aquí y no en el motor porque es la fuente la que sabe qué
     * relaciones tocan sus columnas: sin él, un reporte de mil filas dispara
     * mil consultas y la trampa no se nota hasta que alguien pide el año
     * completo.
     *
     * @param  array<string, mixed>  $filtros  ya saneados y validados
     */
    public function consulta(Usuario $usuario, array $filtros): Builder;

    /**
     * Columna de desempate estable, con tabla: `matricula_oferta.id`.
     *
     * Va SIEMPRE al final del orden. Sin ella, dos filas con el mismo valor en
     * la columna ordenada salen en orden indeterminado y la página 2 repite
     * filas de la 1 — un reporte que se lee dos veces y da dos resultados.
     */
    public function llavePrimaria(): string;
}
