<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { TextStyle, Color, FontFamily, FontSize } from '@tiptap/extension-text-style';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import { Sangria } from '@/Components/editor/sangria';
import { Incrustado } from '@/Components/editor/incrustado';

// Editor de texto enriquecido (TipTap). Emite HTML por v-model. El HTML se
// guarda tal cual y se vuelve a mostrar en el apartado del programa; por eso
// se usan estilos inline conocidos (color, fuente, tamaño, alineación,
// sangría) que un contenedor de solo lectura puede pintar sin más.
const props = defineProps<{
    modelValue: string | null;
    placeholder?: string;
    /**
     * A dónde subir las imágenes que se peguen dentro del texto.
     *
     * Sin esto el botón no aparece: hay editores en el sistema —una nota, una
     * descripción corta— donde subir archivos no viene al caso, y un botón que
     * al pulsarlo diera error sería peor que no tenerlo.
     */
    urlSubidaImagen?: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', valor: string): void }>();

// Se recalcula el estado de la barra en cada cambio de selección o contenido.
const tic = ref(0);

const editor = useEditor({
    content: props.modelValue ?? '',
    extensions: [
        StarterKit,
        TextStyle,
        Color,
        FontFamily,
        FontSize,
        Highlight.configure({ multicolor: true }),
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        Sangria,
        Incrustado,
        // `inline: false`: la imagen es un bloque propio. Dentro de un párrafo
        // se pelea con el texto que la rodea y el resultado es imprevisible.
        Image.configure({ inline: false, allowBase64: false }),
    ],
    editorProps: {
        attributes: { class: 'editor-prosa min-h-[10rem] max-h-[28rem] overflow-y-auto px-4 py-3 focus:outline-none' },
    },
    onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        emit('update:modelValue', html === '<p></p>' ? '' : html);
        tic.value++;
    },
    onSelectionUpdate: () => tic.value++,
});

watch(
    () => props.modelValue,
    (valor) => {
        if (editor.value && (valor ?? '') !== editor.value.getHTML() && (valor ?? '') !== '') {
            editor.value.commands.setContent(valor ?? '', false);
        }
    },
);

/*
 * Insertar contenido externo: un SCORM publicado, un video, una infografía.
 *
 * Se pide la dirección y se exige que sea `https`. Un `http` dentro de una
 * página segura lo bloquea el navegador sin decir nada útil, y el docente vería
 * un recuadro en blanco sin saber por qué: es mejor rechazarlo aquí, donde se
 * puede explicar.
 */
function insertarIncrustado(): void {
    const direccion = window.prompt(
        'Dirección del contenido a incrustar (SCORM, video, infografía).\n'
        + 'Debe empezar con https://',
    );

    if (direccion === null) return;

    const limpia = direccion.trim();

    if (!/^https:\/\/\S+$/i.test(limpia)) {
        window.alert('La dirección debe empezar con https:// para que el navegador la muestre.');

        return;
    }

    editor.value?.chain().focus().insertarIncrustado({ src: limpia }).run();
}

/* ── Imágenes ──────────────────────────────────────────────────────────────
 *
 * Se SUBEN, no se enlazan de otro sitio. Pegar la dirección de una imagen
 * ajena deja el material a merced de un servidor que no es de la escuela: el
 * enlace se cae a mitad del semestre, o lo que hay detrás cambia sin aviso, y
 * cada alumno que abre la lección le anuncia a ese servidor dónde estudia.
 *
 * La subida va por `fetch` y no por el formulario de la página: esto ocurre a
 * media escritura, y una visita de Inertia recargaría la pantalla y se llevaría
 * lo que el docente lleva escrito.
 */
const entradaImagen = ref<HTMLInputElement | null>(null);
const subiendoImagen = ref(false);
const errorImagen = ref<string | null>(null);

function elegirImagen(): void {
    errorImagen.value = null;
    entradaImagen.value?.click();
}

async function subirImagen(evento: Event): Promise<void> {
    const entrada = evento.target as HTMLInputElement;
    const archivo = entrada.files?.[0];

    // Se limpia siempre: sin esto, elegir DOS VECES el mismo archivo no dispara
    // el evento la segunda, y parece que el botón dejó de funcionar.
    entrada.value = '';

    if (!archivo || !props.urlSubidaImagen) return;

    subiendoImagen.value = true;
    errorImagen.value = null;

    try {
        const cuerpo = new FormData();
        cuerpo.append('imagen', archivo);

        const respuesta = await fetch(props.urlSubidaImagen, {
            method: 'POST',
            body: cuerpo,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
        });

        if (!respuesta.ok) {
            /*
             * 419 es la sesión caducada, y es el error MÁS probable aquí: se
             * escribe una lección con la pestaña abierta media hora y el token
             * ya no sirve. El mensaje que manda el servidor en ese caso habla de
             * tokens; al docente hay que decirle qué hacer, y sobre todo que no
             * pierda lo escrito —recargar sí lo perdería—.
             */
            if (respuesta.status === 419) {
                errorImagen.value = 'Tu sesión caducó. Copia lo que llevas escrito, '
                    + 'vuelve a entrar y pégalo: si recargas ahora, se pierde.';

                return;
            }

            const datos = await respuesta.json().catch(() => null);

            // El mensaje del servidor dice lo que de verdad pasó —el formato no
            // se admite, pesa de más—; el genérico solo dice que algo falló.
            errorImagen.value = datos?.message
                ?? datos?.errors?.imagen?.[0]
                ?? 'No se pudo subir la imagen.';

            return;
        }

        const { url, ancho, alto } = await respuesta.json();

        // Con las medidas puestas, la página reserva el hueco antes de que la
        // imagen llegue y no da el salto al cargar. Van como atributos del
        // `<img>`; el CSS de la lección las deja fluir (`max-width:100%`).
        editor.value
            ?.chain()
            .focus()
            .setImage({ src: url, alt: archivo.name, width: ancho ?? null, height: alto ?? null })
            .run();
    } catch {
        errorImagen.value = 'No se pudo subir la imagen. Revisa tu conexión.';
    } finally {
        subiendoImagen.value = false;
    }
}

// --- Catálogos de la barra ---
const fuentes = [
    { texto: 'Fuente', valor: '' },
    { texto: 'Arial', valor: 'Arial, sans-serif' },
    { texto: 'Georgia', valor: 'Georgia, serif' },
    { texto: 'Times New Roman', valor: '"Times New Roman", serif' },
    { texto: 'Courier', valor: '"Courier New", monospace' },
    { texto: 'Verdana', valor: 'Verdana, sans-serif' },
    { texto: 'Trebuchet', valor: '"Trebuchet MS", sans-serif' },
];

const tamanos = [
    { texto: 'Tamaño', valor: '' },
    { texto: 'Pequeño', valor: '12px' },
    { texto: 'Normal', valor: '14px' },
    { texto: 'Mediano', valor: '18px' },
    { texto: 'Grande', valor: '24px' },
    { texto: 'Enorme', valor: '32px' },
];

// --- Estado actual (reactivo por `tic`) ---
const fuenteActual = computed(() => (tic.value, editor.value?.getAttributes('textStyle').fontFamily ?? ''));
const tamanoActual = computed(() => (tic.value, editor.value?.getAttributes('textStyle').fontSize ?? ''));
const colorActual = computed(() => (tic.value, editor.value?.getAttributes('textStyle').color ?? '#000000'));
const resaltadoActual = computed(() => (tic.value, editor.value?.getAttributes('highlight').color ?? '#ffff00'));

function activo(nombre: string, args?: Record<string, unknown>): boolean {
    void tic.value;
    return editor.value?.isActive(nombre, args) ?? false;
}

// --- Acciones ---
function ponerFuente(valor: string): void {
    const c = editor.value?.chain().focus();
    valor ? c?.setFontFamily(valor).run() : c?.unsetFontFamily().run();
}

function ponerTamano(valor: string): void {
    const c = editor.value?.chain().focus();
    valor ? c?.setFontSize(valor).run() : c?.unsetFontSize().run();
}

function ponerColor(evento: Event): void {
    editor.value?.chain().focus().setColor((evento.target as HTMLInputElement).value).run();
}

function ponerResaltado(evento: Event): void {
    editor.value?.chain().focus().setHighlight({ color: (evento.target as HTMLInputElement).value }).run();
}

// Botones simples: nombre de la marca/nodo activo + acción de conmutar.
const marcas = [
    { icono: 'B', clase: 'font-bold', titulo: 'Negrita', activo: 'bold', run: () => editor.value?.chain().focus().toggleBold().run() },
    { icono: 'I', clase: 'italic', titulo: 'Cursiva', activo: 'italic', run: () => editor.value?.chain().focus().toggleItalic().run() },
    { icono: 'U', clase: 'underline', titulo: 'Subrayado', activo: 'underline', run: () => editor.value?.chain().focus().toggleUnderline().run() },
    { icono: 'S', clase: 'line-through', titulo: 'Tachado', activo: 'strike', run: () => editor.value?.chain().focus().toggleStrike().run() },
] as const;

const encabezados = [
    { icono: 'H1', nivel: 1 as const },
    { icono: 'H2', nivel: 2 as const },
    { icono: 'H3', nivel: 3 as const },
];

const alineaciones = [
    { icono: '⯇', titulo: 'Izquierda', valor: 'left' },
    { icono: '≡', titulo: 'Centrar', valor: 'center' },
    { icono: '⯈', titulo: 'Derecha', valor: 'right' },
    { icono: '⬌', titulo: 'Justificar', valor: 'justify' },
];
</script>

<template>
    <div class="overflow-hidden rounded-lg ring-1" :style="{ '--tw-ring-color': 'var(--color-borde)' }">
        <div
            v-if="editor"
            class="flex flex-wrap items-center gap-1 border-b px-2 py-1.5"
            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }"
        >
            <!-- Fuente y tamaño -->
            <select
                class="rounded border px-1.5 py-1 text-xs"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }"
                :value="fuenteActual"
                @change="ponerFuente(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="f in fuentes" :key="f.texto" :value="f.valor">{{ f.texto }}</option>
            </select>
            <select
                class="rounded border px-1.5 py-1 text-xs"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }"
                :value="tamanoActual"
                @change="ponerTamano(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="t in tamanos" :key="t.texto" :value="t.valor">{{ t.texto }}</option>
            </select>

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <!-- Marcas de texto -->
            <button
                v-for="m in marcas"
                :key="m.titulo"
                type="button"
                :title="m.titulo"
                class="h-7 w-7 rounded text-xs"
                :class="m.clase"
                :style="activo(m.activo) ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-contenido)' }"
                @click="m.run"
            >
                {{ m.icono }}
            </button>

            <!-- Color de texto y resaltado -->
            <label class="flex h-7 items-center gap-1 rounded px-1 text-xs" :title="'Color de texto'" :style="{ color: 'var(--color-contenido)' }">
                <span class="font-semibold">A</span>
                <input type="color" class="h-5 w-5 cursor-pointer border-0 bg-transparent p-0" :value="colorActual" @input="ponerColor" />
            </label>
            <label class="flex h-7 items-center gap-1 rounded px-1 text-xs" :title="'Resaltado'" :style="{ color: 'var(--color-contenido)' }">
                <span>🖉</span>
                <input type="color" class="h-5 w-5 cursor-pointer border-0 bg-transparent p-0" :value="resaltadoActual" @input="ponerResaltado" />
            </label>

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <!-- Encabezados -->
            <button
                v-for="h in encabezados"
                :key="h.icono"
                type="button"
                :title="`Encabezado ${h.nivel}`"
                class="h-7 rounded px-1.5 text-xs font-semibold"
                :style="activo('heading', { level: h.nivel }) ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-contenido)' }"
                @click="editor?.chain().focus().toggleHeading({ level: h.nivel }).run()"
            >
                {{ h.icono }}
            </button>

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <!-- Listas -->
            <button type="button" title="Lista con viñetas" class="h-7 rounded px-1.5 text-xs" :style="activo('bulletList') ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-contenido)' }" @click="editor?.chain().focus().toggleBulletList().run()">• Lista</button>
            <button type="button" title="Lista numerada" class="h-7 rounded px-1.5 text-xs" :style="activo('orderedList') ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-contenido)' }" @click="editor?.chain().focus().toggleOrderedList().run()">1. Lista</button>

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <!-- Alineación -->
            <button
                v-for="a in alineaciones"
                :key="a.valor"
                type="button"
                :title="a.titulo"
                class="h-7 w-7 rounded text-xs"
                :style="activo({ textAlign: a.valor } as any) || editor?.isActive({ textAlign: a.valor }) ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-contenido)' }"
                @click="editor?.chain().focus().setTextAlign(a.valor).run()"
            >
                {{ a.icono }}
            </button>

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <!-- Sangría -->
            <button type="button" title="Reducir sangría" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().reducirSangria().run()">⇤</button>
            <button type="button" title="Aumentar sangría" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().aumentarSangria().run()">⇥</button>

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <!-- Bloques y limpieza -->
            <button type="button" title="Cita" class="h-7 w-7 rounded text-xs" :style="activo('blockquote') ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-contenido)' }" @click="editor?.chain().focus().toggleBlockquote().run()">❝</button>
            <button type="button" title="Línea divisoria" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().setHorizontalRule().run()">―</button>
            <button type="button" title="Quitar formato" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().unsetAllMarks().clearNodes().run()">⌫</button>

            <!-- Lo que la escuela ya tiene producido: un SCORM, un video, una
                 infografía. Sin esto, «cargar contenido» sería reescribirlo. -->
            <button
                type="button"
                title="Insertar contenido externo (SCORM, video, infografía)"
                class="h-7 rounded px-1.5 text-xs"
                :style="{ color: 'var(--color-contenido)' }"
                @click="insertarIncrustado"
            >
                ⧉ Incrustar
            </button>

            <!-- Imagen: sólo donde el editor sabe a dónde subirla. -->
            <template v-if="urlSubidaImagen">
                <button
                    type="button"
                    title="Subir una imagen e insertarla aquí"
                    class="h-7 rounded px-1.5 text-xs disabled:opacity-60"
                    :style="{ color: 'var(--color-contenido)' }"
                    :disabled="subiendoImagen"
                    @click="elegirImagen"
                >
                    {{ subiendoImagen ? 'Subiendo…' : '🖼 Imagen' }}
                </button>
                <input
                    ref="entradaImagen"
                    type="file"
                    class="hidden"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    @change="subirImagen"
                />
            </template>

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <button type="button" title="Deshacer" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().undo().run()">↶</button>
            <button type="button" title="Rehacer" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().redo().run()">↷</button>
        </div>

        <!-- El error de la subida, junto al botón que la disparó: en un toast se
             pierde cuando el docente ya está mirando otra cosa. -->
        <p
            v-if="errorImagen"
            class="border-b px-3 py-2 text-xs"
            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, #dc2626 8%, transparent)', color: '#b91c1c' }"
        >
            {{ errorImagen }}
        </p>

        <EditorContent :editor="editor" :style="{ backgroundColor: 'var(--color-superficie)' }" />
    </div>
</template>

<style scoped>
/*
 * El contenido incrustado, dentro del editor: con borde para que se vea dónde
 * empieza y termina el bloque, ya que por dentro no se puede escribir.
 */
.editor-prosa :deep(iframe.incrustado) {
    display: block;
    width: 100%;
    margin: 0.75rem 0;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background-color: color-mix(in srgb, var(--color-suave) 6%, transparent);
}
.editor-prosa :deep(iframe.incrustado.ProseMirror-selectednode) {
    outline: 2px solid var(--color-acento);
    outline-offset: 2px;
}

/* La imagen, acotada al ancho del editor: una foto de cámara mide 4000 px y
   sin esto empuja la barra de herramientas fuera de la pantalla. */
.editor-prosa :deep(img) {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0.75rem 0;
    border-radius: 0.5rem;
}
.editor-prosa :deep(img.ProseMirror-selectednode) {
    outline: 2px solid var(--color-acento);
    outline-offset: 2px;
}

.editor-prosa :deep(h1) {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0.7rem 0 0.35rem;
}
.editor-prosa :deep(h2) {
    font-size: 1.2rem;
    font-weight: 700;
    margin: 0.6rem 0 0.3rem;
}
.editor-prosa :deep(h3) {
    font-size: 1.05rem;
    font-weight: 600;
    margin: 0.5rem 0 0.25rem;
}
.editor-prosa :deep(p) {
    margin: 0.35rem 0;
}
.editor-prosa :deep(ul) {
    list-style: disc;
    padding-left: 1.5rem;
    margin: 0.35rem 0;
}
.editor-prosa :deep(ol) {
    list-style: decimal;
    padding-left: 1.5rem;
    margin: 0.35rem 0;
}
.editor-prosa :deep(blockquote) {
    border-left: 3px solid var(--color-borde);
    padding-left: 0.75rem;
    color: var(--color-suave);
    margin: 0.4rem 0;
}
.editor-prosa :deep(hr) {
    border: none;
    border-top: 1px solid var(--color-borde);
    margin: 0.8rem 0;
}
.editor-prosa :deep(a) {
    color: var(--color-acento);
    text-decoration: underline;
}
.editor-prosa :deep(mark) {
    border-radius: 0.15rem;
    padding: 0 0.1rem;
}
</style>
