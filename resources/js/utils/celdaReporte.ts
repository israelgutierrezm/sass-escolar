/**
 * Cómo se PINTA la celda de un reporte, según lo que el dato ES.
 *
 * ── Por qué existe ────────────────────────────────────────────────────────
 * `TipoDato` decide tres cosas en el servidor —la alineación, el formato de la
 * celda del Excel y qué agregaciones tienen sentido— y la pantalla lo estaba
 * ignorando: pintaba `fila[clave]` crudo. El resultado, mirado en el navegador:
 *
 *   Total 2750.00 | Cobrado 0 | Por cobrar 2750 | Vence 2026-08-05T06:00:00.000000Z
 *
 * Tres formatos de dinero en la MISMA fila —porque unos vienen del SELECT como
 * cadena y otros de una closure como número— y una fecha en ISO con zona
 * horaria, que es exactamente lo que nadie quiere leer en un corte de caja.
 *
 * Ninguna de las dos cosas da error. Es la clase de defecto que sólo aparece
 * mirando la pantalla.
 */

/** Los tipos que declara `App\Reportes\TipoDato`. */
export type TipoDato =
    | 'texto'
    | 'entero'
    | 'decimal'
    | 'dinero'
    | 'fecha'
    | 'fecha_hora'
    | 'booleano'
    | 'porcentaje';

const MONEDA = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const DECIMAL = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ENTERO = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 });

/**
 * Una fecha del servidor, en formato mexicano.
 *
 * Llega como ISO —`2026-08-05T06:00:00.000000Z`— o como `AAAA-MM-DD`. El
 * segundo caso se parte a mano y NO se pasa por `new Date()`: ahí el navegador
 * lo interpreta como UTC y en México lo dibuja un día antes. Es la misma trampa
 * que ya documentó `hoyLocal()`.
 */
function fecha(valor: string, conHora: boolean): string {
    const soloFecha = /^\d{4}-\d{2}-\d{2}$/.exec(valor);

    if (soloFecha) {
        const [a, m, d] = valor.split('-');

        return `${d}/${m}/${a}`;
    }

    const t = new Date(valor);

    if (Number.isNaN(t.getTime())) {
        return valor;
    }

    const partes: Intl.DateTimeFormatOptions = conHora
        ? { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { day: '2-digit', month: '2-digit', year: 'numeric' };

    return new Intl.DateTimeFormat('es-MX', partes).format(t);
}

/**
 * El texto de una celda.
 *
 * Un vacío se dibuja con una raya y NO con un cero: «no se capturó» y «vale
 * cero» son cosas distintas, y es la misma regla que gobierna la captura de
 * calificaciones de este proyecto.
 */
export function celdaReporte(valor: unknown, tipo: TipoDato): string {
    if (valor === null || valor === undefined || valor === '') {
        return '—';
    }

    if (tipo === 'booleano') {
        return valor === true || valor === 1 || valor === '1' ? 'Sí' : 'No';
    }

    if (tipo === 'fecha' || tipo === 'fecha_hora') {
        return typeof valor === 'string' ? fecha(valor, tipo === 'fecha_hora') : String(valor);
    }

    // El resto son números, y llegan como cadena o como número según vengan del
    // SELECT o de una closure. Se normaliza antes de formatear: sin esto,
    // «2750.00» y 2750 se pintan distinto en la misma columna.
    if (tipo === 'entero' || tipo === 'decimal' || tipo === 'dinero' || tipo === 'porcentaje') {
        const n = typeof valor === 'number' ? valor : Number(valor);

        if (Number.isNaN(n)) {
            return String(valor);
        }

        if (tipo === 'dinero') return MONEDA.format(n);
        if (tipo === 'entero') return ENTERO.format(n);
        if (tipo === 'porcentaje') return `${DECIMAL.format(n)} %`;

        return DECIMAL.format(n);
    }

    return String(valor);
}
