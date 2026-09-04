<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Models\Permanencia\EstadoCaso;
use App\Reportes\DefinicionReporte;

/**
 * Los casos cerrados, con su desenlace y con lo que pasó de verdad.
 *
 * ── Las DOS cifras, y por qué hacen falta las dos ─────────────────────────
 * «¿Sirvió?» sale de la bandera del motivo del cierre: es lo DECLARADO por
 * quien cerró. «La señal mejoró» sale del estado de las señales que lo
 * originaron: es lo MEDIDO. Un caso cerrado con éxito cuya señal sigue abierta
 * no es necesariamente un error —la mejora puede tardar en reflejarse— pero es
 * exactamente lo que hay que poder ver: con una sola columna nadie puede saber
 * si el indicador dice algo.
 *
 * Y por eso se ordena por fecha de cierre y no por resultado: se lee para
 * revisar un periodo, no para presumir una cifra.
 */
class EfectividadDelAcompanamiento extends DefinicionReporte
{
    public function clave(): string
    {
        return 'efectividad-del-acompanamiento';
    }

    public function titulo(): string
    {
        return 'Casos cerrados y su desenlace';
    }

    public function descripcion(): string
    {
        return 'Lo que se cerró, con qué motivo, si ese motivo cuenta como éxito y si las señales '
            .'que lo originaron dejaron de cumplirse. Las dos cosas: lo declarado al cerrar y lo '
            .'que de verdad cambió.';
    }

    public function fuente(): string
    {
        return 'casos_permanencia';
    }

    public function areaSugerida(): string
    {
        return 'permanencia';
    }

    public function filtrosFijos(): array
    {
        return ['estado' => [EstadoCaso::Cerrado->value]];
    }

    public function columnasPorOmision(): ?array
    {
        return ['folio', 'alumno', 'programa_academico', 'campus', 'generacion',
            'motivo_cierre', 'cuenta_como_exito', 'senal_resuelta', 'dias_abierto',
            'horas_primer_contacto', 'intervenciones'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['cerrado_en', 'desc'];
    }
}
