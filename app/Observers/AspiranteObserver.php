<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Admisiones\Aspirante;
use App\Models\Identidad\Persona;
use App\Services\AprovisionadorAcceso;

/**
 * Al registrar un aspirante, la persona pasa a ser usuario con rol `aspirante`.
 *
 * Cubre tanto el alta desde el CRM como el registro autogestivo del formulario
 * público. En el flujo público, si además viene contraseña, `RegistradorProspecto`
 * la fija sobre esta misma cuenta y la marca con acceso configurado —el observer
 * solo garantiza que la cuenta y el rol existan—.
 */
class AspiranteObserver
{
    public function __construct(private readonly AprovisionadorAcceso $aprovisionador) {}

    public function created(Aspirante $aspirante): void
    {
        $persona = Persona::find($aspirante->persona_id);

        if ($persona !== null) {
            $this->aprovisionador->paraPersona($persona, 'aspirante');
        }
    }
}
