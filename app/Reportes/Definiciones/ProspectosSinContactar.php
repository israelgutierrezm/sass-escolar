<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Con quién NADIE ha hablado todavía.
 *
 * ── Es una COLA DE TRABAJO y por eso ordena por antigüedad ───────────────
 * El más viejo primero: un prospecto que lleva tres semanas sin que nadie le
 * conteste el teléfono es el que está a punto de perderse. Ordenado al revés,
 * la lista enseñaría los de esta mañana, que son los que menos urgen.
 *
 * ── «Sin contactar» NO es «sin intentos» ─────────────────────────────────
 * Se mide por la bandera `cuenta_como_contacto` del catálogo de resultados: a
 * quien se le marcó seis veces sin que contestara sigue estando sin contactar,
 * y ése es justo el que hay que atender de otra forma.
 */
class ProspectosSinContactar extends DefinicionReporte
{
    public function clave(): string
    {
        return 'prospectos-sin-contactar';
    }

    public function titulo(): string
    {
        return 'Prospectos sin contactar';
    }

    public function descripcion(): string
    {
        return 'Prospectos abiertos con los que todavía no ha hablado nadie. NO es «sin intentos»: '
            .'quien recibió seis llamadas sin contestar sigue aquí, porque lo que cuenta es el '
            .'contacto efectivo. Los más antiguos arriba, que son los que están a punto de perderse.';
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
        return ['desenlace' => 'abierto', 'sin_contactar' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['clave_aspirante', 'nombre', 'celular', 'campus', 'programa', 'etapa', 'actividades', 'registrado_en'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['registrado_en', 'asc'];
    }
}
