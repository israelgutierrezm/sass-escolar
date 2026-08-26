<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * El embudo vivo: a quién hay que seguir atendiendo.
 *
 * «Abierto» se DERIVA —ni descartado ni inscrito— y por eso es fijo: sugerirlo
 * como filtro dejaría que alguien lo quitara sin darse cuenta y presentara en
 * una junta un embudo que incluye a los que ya se cayeron y a los que ya entraron.
 */
class ProspectosAbiertos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'prospectos-abiertos';
    }

    public function titulo(): string
    {
        return 'Prospectos abiertos';
    }

    public function descripcion(): string
    {
        return 'Los aspirantes que siguen vivos en el embudo: ni descartados ni inscritos. Una fila es '
            .'una POSTULACIÓN, no una persona —quien se postuló a dos programas aparece dos veces—. '
            .'Y «actividades» NO son contactos: marcarle seis veces sin que conteste son seis '
            .'actividades y cero contactos.';
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
        return ['desenlace' => 'abierto'];
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave_aspirante', 'nombre', 'campus', 'programa', 'etapa', 'promotor', 'contactos', 'ultimo_contacto'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['registrado_en', 'desc'];
    }
}
