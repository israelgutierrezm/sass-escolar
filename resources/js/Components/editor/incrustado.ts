import { Node, mergeAttributes } from '@tiptap/core';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        incrustado: {
            /** Inserta un `iframe` con la dirección dada. */
            insertarIncrustado: (opciones: { src: string; alto?: number }) => ReturnType;
        };
    }
}

/**
 * Contenido incrustado: un `iframe` dentro del material de la actividad.
 *
 * Es lo que permite que una escuela use en Acadion lo que ya tiene producido
 * —un SCORM publicado, un video, una infografía de Genially, un formulario—
 * sin pedirle que lo rehaga aquí. Sin esto, «cargar contenido» significaría
 * volver a escribirlo todo en texto plano.
 *
 * ── Por qué un nodo y no HTML libre ────────────────────────────────────────
 * Dejar pegar HTML arbitrario en el editor abriría la puerta a que el material
 * de una materia trajera scripts que corren en la sesión de cada alumno que la
 * abre. Un nodo acotado guarda exactamente tres cosas —dirección, alto y
 * título— y al renderizar produce un `iframe` y nada más.
 */
export const Incrustado = Node.create({
    name: 'incrustado',

    group: 'block',
    atom: true,          // se selecciona y borra entero: no se escribe dentro
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            src: { default: null },
            alto: {
                default: 480,
                parseHTML: (el) => Number(el.getAttribute('height')) || 480,
                renderHTML: (attrs) => ({ height: attrs.alto }),
            },
            title: { default: 'Contenido incrustado' },
        };
    },

    parseHTML() {
        return [{ tag: 'iframe[src]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'iframe',
            mergeAttributes(HTMLAttributes, {
                class: 'incrustado',
                width: '100%',
                frameborder: '0',
                allowfullscreen: 'true',
                /*
                 * El material lo carga la escuela, pero se sirve dentro de la
                 * sesión del alumno: se le quitan al marco los permisos que no
                 * necesita para mostrar contenido.
                 */
                sandbox: 'allow-scripts allow-same-origin allow-forms allow-popups allow-presentation',
                referrerpolicy: 'no-referrer',
            }),
        ];
    },

    addCommands() {
        return {
            insertarIncrustado:
                ({ src, alto }) =>
                ({ commands }) =>
                    commands.insertContent({
                        type: this.name,
                        attrs: { src, alto: alto ?? 480 },
                    }),
        };
    },
});
