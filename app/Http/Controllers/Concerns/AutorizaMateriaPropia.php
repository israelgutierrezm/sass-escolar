<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El alcance del docente sale de su ASIGNACIÓN, no de un permiso.
 *
 * El permiso dice que puede dar clase; la asignación dice en qué materia. Un
 * docente con `capturar-calificaciones` y sin esa materia recibe 403.
 *
 * Vivía copiado en cuatro controladores del portal del docente. Una regla de
 * acceso repetida es una regla que tarde o temprano se corrige en tres lugares
 * y se olvida en el cuarto —y el que se olvida es el que deja entrar—.
 */
trait AutorizaMateriaPropia
{
    protected function autorizarMateriaPropia(Request $request, AsignaturaGrupo $asignaturaGrupo): void
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        // Control escolar entra a cualquier materia; el docente, solo a la suya.
        if ($usuario->can('abrir-grupos')) {
            return;
        }

        // La relación cuelga de `docentes` (PK persona_id), no de `personas`.
        $esSuya = $usuario->persona_id !== null && $asignaturaGrupo->docentes()
            ->where('docentes.persona_id', $usuario->persona_id)
            ->exists();

        if (! $esSuya) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }
    }
}
