<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Los que ya terminaron, con su folio.
 *
 * El que se presume: cuántos egresados cumplieron su servicio social y con qué
 * organizaciones. La columna de folio va en la vista por omisión porque es lo
 * que un tercero pide para verificar, y sale de la liberación VIGENTE — el
 * folio de una corregida ya no ampara nada.
 */
class ProcesosLiberados extends DefinicionReporte
{
    public function clave(): string
    {
        return 'procesos-liberados';
    }

    public function titulo(): string
    {
        return 'Liberados';
    }

    public function descripcion(): string
    {
        return 'Los expedientes con constancia emitida, con su folio vigente y las horas que se le '
            .'acreditaron. Si una constancia se corrigió, el folio que sale es el nuevo.';
    }

    public function fuente(): string
    {
        return 'expedientes_formativos';
    }

    public function areaSugerida(): string
    {
        return 'procesos-formativos';
    }

    public function filtrosFijos(): array
    {
        return ['estado' => ['liberado']];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'tipo', 'organizacion',
            'horas_aprobadas', 'folio_liberacion'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha_fin_programada', 'desc'];
    }
}
