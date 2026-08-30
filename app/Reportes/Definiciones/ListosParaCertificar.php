<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * A quién se le puede armar el lote HOY.
 *
 * Tres condiciones fijas y baratas: cerró su plan, su programa académico expide documentos
 * oficiales y no está ya en otro trámite. Es exactamente lo que
 * `EstadoCertificacion::elegibleParaLote()` decide para el buscador de
 * candidatos — el mismo criterio, puesto en forma de lista.
 */
class ListosParaCertificar extends DefinicionReporte
{
    public function clave(): string
    {
        return 'listos-para-certificar';
    }

    public function titulo(): string
    {
        return 'Listos para certificar';
    }

    public function descripcion(): string
    {
        return 'Matrículas que cerraron su plan y todavía no están en ningún lote. NO garantiza que el '
            .'lote se pueda FIRMAR: eso lo decide el validador de la SEP al armarlo, y lo más común '
            .'que lo detiene es el identificador oficial del campus, el programa académico o una asignatura. '
            .'Revisa la columna «Id. del campus»: si está vacía, el lote entero se detiene.';
    }

    public function fuente(): string
    {
        return 'certificables';
    }

    public function areaSugerida(): string
    {
        return 'certificacion';
    }

    public function filtrosFijos(): array
    {
        return ['cerro_plan' => true, 'sin_tramite' => true, 'expide_documentos' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'campus', 'generacion', 'aprobadas', 'meta', 'campus_identificador'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['matricula', 'asc'];
    }
}
