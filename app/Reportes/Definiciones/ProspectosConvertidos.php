<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quiénes SÍ se inscribieron, y por qué origen llegaron.
 *
 * Es la mitad útil del embudo: sin ella «captamos 400 prospectos» no dice nada.
 * El origen es lo que convierte esto en una decisión de gasto — de dónde vienen
 * los que sí entran.
 */
class ProspectosConvertidos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'prospectos-convertidos';
    }

    public function titulo(): string
    {
        return 'Prospectos convertidos';
    }

    public function descripcion(): string
    {
        return 'Los aspirantes que llegaron a matricularse EN SU PROGRAMA DE INTERÉS. Quien se '
            .'inscribió a otra carrera distinta de la que preguntó NO cuenta aquí, y es correcto: su '
            .'postulación original sigue abierta. NO dice cuántos siguen estudiando —para eso está '
            .'«Alumnos inscritos»—.';
    }

    public function fuente(): string
    {
        return 'aspirantes';
    }

    public function areaSugerida(): string
    {
        return 'admisiones';
    }

    public function filtrosFijos(): array
    {
        return ['desenlace' => 'inscrito'];
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave_aspirante', 'nombre', 'campus', 'programa', 'origen', 'promotor', 'contactos', 'registrado_en'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['registrado_en', 'desc'];
    }
}
