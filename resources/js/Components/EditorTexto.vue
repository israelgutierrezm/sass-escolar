<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { TextStyle, Color, FontFamily, FontSize } from '@tiptap/extension-text-style';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import { Sangria } from '@/Components/editor/sangria';

// Editor de texto enriquecido (TipTap). Emite HTML por v-model. El HTML se
// guarda tal cual y se vuelve a mostrar en el apartado del programa; por eso
// se usan estilos inline conocidos (color, fuente, tamaño, alineación,
// sangría) que un contenedor de solo lectura puede pintar sin más.
const props = defineProps<{
    modelValue: string | null;
    placeholder?: string;
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

            <span class="mx-1 h-5 w-px" :style="{ backgroundColor: 'var(--color-borde)' }" />

            <button type="button" title="Deshacer" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().undo().run()">↶</button>
            <button type="button" title="Rehacer" class="h-7 w-7 rounded text-xs" :style="{ color: 'var(--color-contenido)' }" @click="editor?.chain().focus().redo().run()">↷</button>
        </div>

        <EditorContent :editor="editor" :style="{ backgroundColor: 'var(--color-superficie)' }" />
    </div>
</template>

<style scoped>
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
