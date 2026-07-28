<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\IdentidadPersona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Eco en vivo de la CURP mientras se captura.
 *
 * No guarda nada: solo lee lo que la CURP trae dentro y avisa si esa persona ya
 * está en la base. Existe para que el formulario no obligue a recapturar fecha
 * de nacimiento, género y entidad —tres campos que la propia CURP ya contiene—
 * y para que un duplicado se detecte ANTES de llenar veinte campos, no al
 * intentar guardar.
 *
 * Es de lectura y no revela nada que quien captura no vaya a ver de todos
 * modos al reutilizar la persona, así que basta con estar autenticado.
 */
class IdentidadController extends Controller
{
    public function analizarCurp(Request $request, IdentidadPersona $identidad): JsonResponse
    {
        $datos = $request->validate([
            'curp' => ['nullable', 'string', 'max:20'],
            // Al EDITAR, la persona no es duplicado de sí misma.
            'persona_id' => ['nullable', 'integer'],
        ]);

        return response()->json(
            $identidad->analizar($datos['curp'] ?? null, $datos['persona_id'] ?? null),
        );
    }

    /**
     * Busca personas que podrían ser la misma antes de crear una nueva.
     *
     * Devuelve candidatos, no un veredicto: dos hermanos comparten apellidos y
     * a veces el correo de la casa, y el sistema no puede negarse a registrar
     * al segundo. Quien captura decide.
     */
    public function posiblesDuplicados(Request $request, IdentidadPersona $identidad): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:255'],
            'primer_apellido' => ['nullable', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:150'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'persona_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'coincidencias' => $identidad->posiblesDuplicados($datos, $datos['persona_id'] ?? null),
        ]);
    }

    /**
     * ¿Este correo ya está en uso por otra persona? Se consulta al teclearlo
     * para avisar de inmediato: el correo es la llave del login y no puede
     * repetirse. Devuelve el nombre de quien lo tiene, para que quien captura
     * sepa si es la misma persona.
     */
    public function correoEnUso(Request $request, IdentidadPersona $identidad): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['nullable', 'string', 'max:150'],
            'persona_id' => ['nullable', 'integer'],
        ]);

        $conflicto = $identidad->correoEnUso($datos['email'] ?? null, $datos['persona_id'] ?? null);

        return response()->json([
            'en_uso' => $conflicto !== null,
            'nombre' => $conflicto?->nombreCompleto(),
        ]);
    }
}
