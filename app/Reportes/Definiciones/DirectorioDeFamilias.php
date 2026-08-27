<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién responde por cada alumno, y cómo localizarlo.
 *
 * Es el directorio que hace falta para convocar a una junta o avisar de una
 * urgencia, y hoy sólo existe abriendo la ficha de cada alumno una por una.
 */
class DirectorioDeFamilias extends DefinicionReporte
{
    public function clave(): string
    {
        return 'directorio-de-familias';
    }

    public function titulo(): string
    {
        return 'Directorio de familias';
    }

    public function descripcion(): string
    {
        return 'Cada vínculo familiar con su parentesco y sus permisos. Una fila es un VÍNCULO: un '
            .'alumno con padre y madre son dos, y un padre con tres hijos en la escuela son tres. NO '
            .'trae nada académico ni financiero del hijo: eso vive en el portal de la familia, con su '
            .'propio permiso por vínculo. Y NO se puede acotar por campus —un vínculo cuelga de dos '
            .'personas y una persona no pertenece a un plantel—, así que sólo lo ejecuta quien ve '
            .'toda la escuela.';
    }

    public function fuente(): string
    {
        return 'vinculos-familiares';
    }

    public function areaSugerida(): string
    {
        return 'familia';
    }

    public function columnasPorOmision(): ?array
    {
        return ['alumno', 'matriculas', 'tutor', 'parentesco', 'telefono', 'emergencia', 'responsable_pago'];
    }

    public function ordenPorOmision(): ?array
    {
        return null;
    }
}
