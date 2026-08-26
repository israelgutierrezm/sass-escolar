<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién está dado de alta y no tiene ninguna materia.
 *
 * ── Dos lecturas, y las dos importan ─────────────────────────────────────
 * O falta asignarle, o sobra en la plantilla. Las dos son decisiones que se
 * toman al armar el ciclo, y hoy no hay pantalla que las junte: el listado de
 * docentes enseña la carga pero no deja filtrar por «ninguna».
 *
 * **Exige elegir ciclo.** Sin él la pregunta no está hecha: alguien que nunca
 * ha dado clase y alguien que este ciclo no tiene materias son cosas distintas,
 * y sin ciclo el reporte sólo encontraría a los primeros.
 */
class DocentesSinCarga extends DefinicionReporte
{
    public function clave(): string
    {
        return 'docentes-sin-carga';
    }

    public function titulo(): string
    {
        return 'Docentes sin carga';
    }

    public function descripcion(): string
    {
        return 'Docentes activos sin ninguna materia asignada en el ciclo elegido: o falta asignarles, '
            .'o sobran en la plantilla. NO cuenta las materias que ya se les retiraron como si '
            .'siguieran vigentes. Exige elegir ciclo, porque «nunca ha dado clase» y «este ciclo no '
            .'tiene materias» son preguntas distintas.';
    }

    public function fuente(): string
    {
        return 'docentes';
    }

    public function areaSugerida(): string
    {
        return 'docentes';
    }

    public function filtrosFijos(): array
    {
        return ['sin_carga' => true];
    }

    public function filtrosObligatorios(): array
    {
        return ['ciclo_id'];
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave_profesor', 'docente', 'tipo', 'situacion', 'campus', 'cedula_profesional'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['clave_profesor', 'asc'];
    }
}
