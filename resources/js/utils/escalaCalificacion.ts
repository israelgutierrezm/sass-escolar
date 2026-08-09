/**
 * El color de una calificación, según la escala de la escuela.
 *
 * Los umbrales estaban clavados en tres componentes: verde desde 8, ámbar desde
 * 6. Eso solo dice la verdad en una escuela que califique de 0 a 10 y apruebe
 * con 6. En una que califique sobre 100, un 70 impecable se pintaba de rojo.
 *
 * Aquí se derivan de la escala: rojo debajo de la aprobatoria, y el corte del
 * verde a media distancia entre aprobar y la máxima. Con 0–10 y aprobatoria 6
 * eso da exactamente 8, así que las escuelas que ya usaban la escala de siempre
 * no ven ningún cambio.
 */

export const VERDE = '#16a34a';
export const AMBAR = '#d97706';
export const ROJO = '#dc2626';

export interface Escala {
    /** Con cuánto se aprueba. */
    aprobatoria: number;
    /** El techo de la escala. */
    maxima: number;
    /** El piso. Opcional porque casi siempre es cero. */
    minima?: number;
}

/** La escala de siempre, para cuando la pantalla todavía no recibe la del plan. */
export const ESCALA_POR_DEFECTO: Escala = { aprobatoria: 6, maxima: 10, minima: 0 };

export function colorCalificacion(valor: number | null, escala: Escala = ESCALA_POR_DEFECTO): string {
    if (valor === null || Number.isNaN(valor)) {
        return 'var(--color-suave)';
    }

    const { aprobatoria, maxima } = escala;

    if (valor < aprobatoria) {
        return ROJO;
    }

    // Una escala mal capturada —máxima por debajo de la aprobatoria— no debe
    // dejar la pantalla sin color: aprobó, y con eso basta para el verde.
    const corte = maxima > aprobatoria ? aprobatoria + (maxima - aprobatoria) / 2 : aprobatoria;

    return valor >= corte ? VERDE : AMBAR;
}

/**
 * El color de unos PUNTOS, que es como califica el LMS.
 *
 * Se convierten a la escala antes de decidir: el docente ve «32 / 40» y el
 * color tiene que significar lo mismo que en el acta.
 */
export function colorPorPuntos(
    obtenidos: number | null,
    posibles: number,
    escala: Escala = ESCALA_POR_DEFECTO,
): string {
    if (obtenidos === null || Number.isNaN(obtenidos) || posibles <= 0) {
        return 'var(--color-suave)';
    }

    // El mismo mapeo lineal que hace `PlanEstudio::enEscala` en el backend: en
    // una escala de 5 a 10, entregar en blanco es un 5.
    const minima = escala.minima ?? 0;

    return colorCalificacion(minima + (obtenidos / posibles) * (escala.maxima - minima), escala);
}
