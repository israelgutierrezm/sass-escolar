<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * A quién le falta la cédula profesional.
 *
 * ── Por qué es una cola de trabajo y no un dato curioso ──────────────────
 * La escuela puede encender el ajuste `docente.exige_cedula_para_asignar`, y
 * ese día TODOS los que están aquí dejan de poder recibir materias. Es la lista
 * que hay que vaciar antes de armar el ciclo, no después de que la asignación
 * falle una por una en la pantalla de grupos.
 */
class DocentesSinCedula extends DefinicionReporte
{
    public function clave(): string
    {
        return 'docentes-sin-cedula';
    }

    public function titulo(): string
    {
        return 'Docentes sin cédula';
    }

    public function descripcion(): string
    {
        return 'Docentes sin cédula profesional capturada. Importa porque la escuela puede exigirla '
            .'para asignar materias: con ese ajuste encendido, todos los de esta lista quedan fuera '
            .'del ciclo. NO comprueba que la cédula sea válida ante el RNP —sólo que esté capturada—.';
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
        return ['sin_cedula' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave_profesor', 'docente', 'tipo', 'situacion', 'campus', 'materias', 'titulos'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['materias', 'desc'];
    }
}
