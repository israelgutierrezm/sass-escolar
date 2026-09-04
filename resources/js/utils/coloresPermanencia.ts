/**
 * El vocabulario de colores del módulo de permanencia, traducido a CSS.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * `categorias_senal.color`, `niveles_riesgo.color` y `EstadoCaso::color()`
 * guardan un NOMBRE («ambar», «naranja», «morado»), no un hex. Está bien que
 * sea así: una escuela que invente una categoría elige un color de una lista de
 * palabras, no teclea `#d97706`.
 *
 * Lo que estaba mal era pasar ese nombre directo a `PildoraEstado`, que espera
 * un color de CSS. `color: ambar` no es válido, así que el navegador lo
 * DESCARTA —sin error, sin aviso— y la píldora sale con el color heredado y sin
 * fondo. Las píldoras de severidad, de nivel y de categoría del módulo entero
 * llevaban así desde la fase 1: se ven, se leen, y no dicen nada de lo que el
 * color venía a decir. Sólo se vio MIRANDO.
 *
 * ── Y por qué una tabla compartida y no un mapa por pantalla ───────────────
 * Copiada en cada archivo es como se llega a que el «alto» de la bandeja sea de
 * otro naranja que el de la ficha. Es el mismo argumento por el que las
 * etiquetas de acción salieron de `BotonAccion` a `@/utils/acciones`.
 *
 * Los tonos son los mismos que ya usa `PildoraEstado` en su vocabulario para
 * que el módulo no se vea de otro sistema.
 */
const CSS: Record<string, string> = {
    verde: '#16a34a',
    azul: '#2563eb',
    ambar: '#d97706',
    naranja: '#ea580c',
    rojo: '#dc2626',
    morado: '#7c3aed',
    indigo: '#4f46e5',
    rosa: '#db2777',
    gris: 'var(--color-suave)',
};

/**
 * El color de CSS de un nombre del vocabulario.
 *
 * Lo que no reconoce cae a NEUTRO y no a un color llamativo: un nombre mal
 * escrito en un catálogo no puede pintar de rojo una señal que no lo es.
 */
export function colorPermanencia(nombre?: string | null): string {
    return CSS[(nombre ?? '').trim().toLowerCase()] ?? CSS.gris;
}

/** La severidad de una regla, en el mismo vocabulario. */
export const COLOR_SEVERIDAD: Record<string, string> = {
    informativo: 'gris',
    bajo: 'azul',
    medio: 'ambar',
    alto: 'naranja',
    critico: 'rojo',
};

/** La prioridad de un caso. Tres, como las del catálogo. */
export const COLOR_PRIORIDAD: Record<string, string> = {
    alta: 'rojo',
    media: 'ambar',
    baja: 'gris',
};
