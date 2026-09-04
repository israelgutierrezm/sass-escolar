<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién está haciendo su servicio social o sus prácticas AHORA.
 *
 * El caso que se abre a diario: a cuánta gente hay repartida por ahí y en qué
 * organización. Incluye los SUSPENDIDOS a propósito —siguen siendo
 * responsabilidad de la escuela mientras el expediente vive— y por eso la
 * columna de estado va en la vista por omisión: sin ella, un suspendido se
 * cuenta como activo.
 */
class ServicioSocialEnCurso extends DefinicionReporte
{
    public function clave(): string
    {
        return 'procesos-en-curso';
    }

    public function titulo(): string
    {
        return 'Servicio social y prácticas en curso';
    }

    public function descripcion(): string
    {
        return 'Los expedientes que están corriendo hoy, con su organización y su avance en horas. '
            .'Incluye los suspendidos: siguen siendo responsabilidad de la escuela, y la columna de '
            .'estado los distingue. NO cuenta personas: quien hace dos procesos aparece dos veces.';
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
        return ['estado' => ['en_curso', 'suspendido']];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'tipo', 'organizacion',
            'estado', 'horas_aprobadas', 'avance', 'fecha_fin_programada'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha_fin_programada', 'asc'];
    }
}
