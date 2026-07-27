import { Extension } from '@tiptap/core';

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        sangria: {
            aumentarSangria: () => ReturnType;
            reducirSangria: () => ReturnType;
        };
    }
}

const MAX = 8; // niveles de sangría; cada nivel son 2em

/**
 * Sangría de párrafos y encabezados. TipTap no la trae de fábrica: se agrega
 * como atributo global `indent` (0..8) sobre esos nodos, que se renderiza como
 * `margin-left` en em. Tab / Shift+Tab la aumentan y reducen, igual que un
 * procesador de texto.
 */
export const Sangria = Extension.create({
    name: 'sangria',

    addOptions() {
        return { tipos: ['paragraph', 'heading'] as string[] };
    },

    addGlobalAttributes() {
        return [
            {
                types: this.options.tipos,
                attributes: {
                    indent: {
                        default: 0,
                        parseHTML: (el: HTMLElement) => {
                            const ml = parseInt(el.style.marginLeft || '0', 10);
                            return ml ? Math.min(Math.round(ml / 2), MAX) : 0;
                        },
                        renderHTML: (attrs: Record<string, unknown>) => {
                            const nivel = Number(attrs.indent) || 0;
                            return nivel > 0 ? { style: `margin-left: ${nivel * 2}em` } : {};
                        },
                    },
                },
            },
        ];
    },

    addCommands() {
        const ajustar = (delta: number) => () =>
            ({ tr, state, dispatch }: any) => {
                const { selection } = state;
                let cambio = false;

                state.doc.nodesBetween(selection.from, selection.to, (nodo: any, pos: number) => {
                    if (! this.options.tipos.includes(nodo.type.name)) {
                        return;
                    }
                    const actual = Number(nodo.attrs.indent) || 0;
                    const nuevo = Math.max(0, Math.min(actual + delta, MAX));
                    if (nuevo !== actual) {
                        tr.setNodeMarkup(pos, undefined, { ...nodo.attrs, indent: nuevo });
                        cambio = true;
                    }
                });

                if (cambio && dispatch) {
                    dispatch(tr);
                }
                return cambio;
            };

        return {
            aumentarSangria: ajustar(1),
            reducirSangria: ajustar(-1),
        };
    },

    addKeyboardShortcuts() {
        return {
            Tab: () => this.editor.commands.aumentarSangria(),
            'Shift-Tab': () => this.editor.commands.reducirSangria(),
        };
    },
});
