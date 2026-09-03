/**
 * Las acciones de un registro: su etiqueta, su color y su icono.
 *
 * Vivía dentro de `BotonAccion.vue` y salió aquí al aparecer el segundo
 * consumidor —`MenuAcciones.vue`, el menú de tres puntos de los listados—.
 * Copiar el trazo del lápiz y el tono del rojo en dos archivos es como se llega
 * a que el «Eliminar» del menú sea de otro rojo que el del botón, y el
 * significado de un color se pierde en cuanto hay dos.
 */
export type VarianteAccion = 'nuevo' | 'agregar' | 'editar' | 'eliminar' | 'ver' | 'cerrar';

export const ACCIONES: Record<VarianteAccion, { etiqueta: string; color: string; icono: string }> = {
    nuevo: {
        etiqueta: 'Nuevo',
        // Sigue el ACENTO del tema para que combine al cambiarlo; los demás
        // llevan color fijo porque su significado no cambia con el tema.
        color: 'var(--color-acento)',
        icono: 'M12 4.5v15m7.5-7.5h-15',
    },
    agregar: {
        etiqueta: 'Agregar',
        color: 'var(--color-acento)',
        icono: 'M18 7.5v3m0 3v-3m0 0h-3m3 0h3M13.5 10.5a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z',
    },
    editar: {
        etiqueta: 'Editar',
        color: '#B7791F',
        icono: 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125',
    },
    eliminar: {
        etiqueta: 'Eliminar',
        color: '#dc2626',
        icono: 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
    },
    ver: {
        etiqueta: 'Ver',
        color: '#0077B6',
        icono: 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    },
    cerrar: {
        etiqueta: 'Cerrar',
        color: 'var(--color-suave)',
        icono: 'M6 18 18 6M6 6l12 12',
    },
};
