<?php

declare(strict_types=1);

namespace App\Reportes;

/**
 * Un reporte concreto: un PRESET sobre una fuente.
 *
 * ── Qué separa un reporte de un listado ──────────────────────────────────
 * Los `filtrosFijos()`. Un listado enseña todo y deja filtrar; un reporte
 * CONTESTA UNA PREGUNTA, y para eso hay condiciones que quien lo ejecuta no
 * puede aflojar. «Bajas del ciclo» sin la condición de baja fija no es el
 * reporte de bajas: es el listado de matrículas con un filtro sugerido que
 * cualquiera quita sin darse cuenta.
 */
abstract class DefinicionReporte
{
    /** Estable: la guardan la ubicación, las vistas guardadas y la bitácora. */
    abstract public function clave(): string;

    abstract public function titulo(): string;

    /**
     * Qué contesta este reporte Y QUÉ NO.
     *
     * Lo segundo es lo que evita que alguien lo lleve a una junta creyendo que
     * dice otra cosa. «Cuántos alumnos están inscritos hoy» no es lo mismo que
     * «cuántos se inscribieron este ciclo», y la diferencia sólo se ve escrita.
     */
    abstract public function descripcion(): string;

    /** La clave de la fuente de la que sale. */
    abstract public function fuente(): string;

    /** El área en la que nace. La escuela puede moverlo después. */
    public function areaSugerida(): string
    {
        return 'general';
    }

    /**
     * Lo que el reporte impone y quien lo ejecuta NO puede aflojar.
     *
     * @return array<string, mixed>
     */
    public function filtrosFijos(): array
    {
        return [];
    }

    /**
     * Las columnas con las que nace. Null = las de la fuente.
     *
     * @return array<int, string>|null
     */
    public function columnasPorOmision(): ?array
    {
        return null;
    }

    /** @return array{0: string, 1: string}|null ['clave', 'asc'] */
    public function ordenPorOmision(): ?array
    {
        return null;
    }

    /**
     * Filtros sin los cuales el motor se NIEGA a ejecutar.
     *
     * Un reporte de historial sin ciclo barrería la escuela entera: no es que
     * el resultado sea grande, es que la pregunta no está hecha.
     *
     * @return array<int, string>
     */
    public function filtrosObligatorios(): array
    {
        return [];
    }
}
