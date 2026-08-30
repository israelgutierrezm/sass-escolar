<?php

declare(strict_types=1);

namespace App\Credencial;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Credencial;
use App\Models\Identidad\Persona;

/**
 * Quién tiene emitida una credencial, y con qué uuid.
 *
 * ── Por qué se emite sola ──────────────────────────────────────────────────
 * La primera vez que alguien mira su credencial se le asigna su uuid, sin que
 * nadie apriete «emitir». Pedir un trámite previo obligaría a la escuela a dar
 * de alta una por una las credenciales de sus dos mil alumnos para algo que el
 * sistema ya sabe calcular; y quien no la mire nunca no ocupa un renglón.
 *
 * Lo que SÍ está bajo control de la escuela es que el rol emita —`activa` en
 * `credenciales_rol`—. Esta clase no decide eso: sólo reparte identificadores a
 * quien ya tiene derecho a una.
 */
class RegistroDeEmisiones
{
    /**
     * La emisión de esta persona para esta matrícula, creándola si es la
     * primera vez.
     *
     * El rol y la matrícula forman parte de la llave: quien estudia dos
     * programas académicos tiene dos credenciales y cada QR lleva a la suya, y quien da
     * clases y además estudia tiene una por oficio.
     */
    public function de(Persona $persona, int $rolId, ?MatriculaOferta $matricula): Credencial
    {
        return Credencial::query()->firstOrCreate([
            'persona_id' => $persona->id,
            'rol_id' => $rolId,
            'matricula_oferta_id' => $matricula?->id,
        ]);
    }
}
