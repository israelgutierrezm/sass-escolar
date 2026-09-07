/**
 * La fecha de HOY en el huso del navegador, como `AAAA-MM-DD`.
 *
 * ── Por qué no `new Date().toISOString().slice(0, 10)` ────────────────────
 * `toISOString()` devuelve UTC. En México —UTC-6— a partir de las 18:00 locales
 * ya es el día siguiente en UTC, así que ese recorte da MAÑANA toda la tarde y
 * toda la noche. Estaba escrito así en siete pantallas, y en una de ellas
 * —`PaseDeLista`— decidía de qué día era la lista que el docente estaba
 * pasando: una lista de la tarde quedaba anotada al día siguiente.
 *
 * Se descubrió al registrar una colocación por la noche y ver que la fecha de
 * ingreso salía con un día de más.
 */
export function hoyLocal(): string {
    const ahora = new Date();

    // Se arma con las partes locales en vez de convertir: cualquier resta de
    // minutos vuelve a introducir la posibilidad de cruzar la medianoche.
    const mes = `${ahora.getMonth() + 1}`.padStart(2, '0');
    const dia = `${ahora.getDate()}`.padStart(2, '0');

    return `${ahora.getFullYear()}-${mes}-${dia}`;
}

/**
 * Una fecha ISO, escrita como se dice.
 *
 * `2026-07-07` dentro de una frase —«del 2026-07-07 al 2027-01-07»— se lee como
 * un volcado de base de datos, y esto lo lee un alumno sobre su propio
 * expediente. En una CELDA de tabla el ISO está bien: ahí es un dato en una
 * columna y además ordena. Lo que no puede es ir dentro de un párrafo.
 *
 * Devuelve la cadena tal cual si no reconoce el formato: una fecha rara sale
 * legible en vez de «Invalid Date».
 */
export function fechaEnPalabras(iso?: string | null): string {
    const t = (iso ?? '').trim();

    if (!/^\d{4}-\d{2}-\d{2}/.test(t)) return t;

    const [a, m, d] = t.slice(0, 10).split('-').map(Number);
    const meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    return `${d} de ${meses[m - 1]} de ${a}`;
}
