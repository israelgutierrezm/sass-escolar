<?php

declare(strict_types=1);

namespace App\Reportes;

/**
 * Una fuente que además se puede AGRUPAR.
 *
 * ── Por qué es una interfaz aparte y no un método de `FuenteDeReporte` ───
 * Porque «esta fuente no se puede agrupar» es una respuesta legítima y frecuente,
 * y quería expresarse sin obligar a las catorce a escribir `return []`.
 *
 * Una fuente que no la implementa simplemente no ofrece el modo: falla cerrado
 * —el desplegable no aparece y pedirlo por la URL responde 422 con su razón— en
 * vez de ofrecer un agrupado que produzca una fila por grupo, que es lo que
 * saldría de agrupar por la matrícula.
 *
 * ── Cuándo vale la pena implementarla ────────────────────────────────────
 * Cuando la fuente tenga alguna DIMENSIÓN de verdad: pocas categorías y muchas
 * filas. Campus, carrera, situación, concepto, método de pago, etapa. No un
 * identificador —agrupar por matrícula devuelve 32 grupos de 32 filas, que no
 * es agrupar— ni una medida, que es lo que se agrega dentro de cada grupo.
 */
interface FuenteAgrupable
{
    /**
     * Por qué se puede agrupar esta fuente.
     *
     * @return array<string, DimensionReporte>
     */
    public function dimensiones(): array;
}
