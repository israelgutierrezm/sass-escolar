<script setup lang="ts">
import { watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';

// Editor de texto enriquecido (TipTap). Emite HTML por v-model. El HTML se
// guarda tal cual y se vuelve a mostrar en el apartado del programa; por eso
// se limita a bloques semánticos simples (encabezados, listas, negrita…) y no
// a estilos inline arbitrarios.
const props = defineProps<{
    modelValue: string | null;
    placeholder?: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', valor: string): void }>();

const editor = useEditor({
    content: props.modelValue ?? '',
    extensions: [StarterKit],
    editorProps: {
        attributes: {
            class: 'editor-prosa min-h-[9rem] px-3 py-2 focus:outline-none',
        },
    },
    onUpdate: ({ editor }) => {
        // TipTap devuelve '<p></p>' cuando está vacío; lo normalizamos a '' para
        // que el backend lo trate como sin contenido.
        const html = editor.getHTML();
        emit('update:modelValue', html === '<p></p>' ? '' : html);
    },
});

// Si el valor cambia desde fuera (p. ej. reset del form) y difiere de lo que
// tiene el editor, lo sincronizamos sin romper el cursor en tecleo normal.
watch(
    () => props.modelValue,
    (valor) => {
        if (editor.value && (valor ?? '') !== editor.value.getHTML() && (valor ?? '') !== '') {
            editor.value.commands.setContent(valor ?? '', false);
        }
    },
);

const acciones = [
    { icono: 'B', titulo: 'Negrita', activo: 'bold', ejecutar: () => editor.value?.chain().focus().toggleBold().run() },
    { icono: 'I', titulo: 'Cursiva', activo: 'italic', ejecutar: () => editor.value?.chain().focus().toggleItalic().run() },
    { icono: 'H2', titulo: 'Subtítulo', activo: 'heading', args: { level: 2 }, ejecutar: () => editor.value?.chain().focus().toggleHeading({ level: 2 }).run() },
    { icono: 'H3', titulo: 'Apartado', activo: 'heading', args: { level: 3 }, ejecutar: () => editor.value?.chain().focus().toggleHeading({ level: 3 }).run() },
    { icono: '• Lista', titulo: 'Lista con viñetas', activo: 'bulletList', ejecutar: () => editor.value?.chain().focus().toggleBulletList().run() },
    { icono: '1. Lista', titulo: 'Lista numerada', activo: 'orderedList', ejecutar: () => editor.value?.chain().focus().toggleOrderedList().run() },
    { icono: '❝', titulo: 'Cita', activo: 'blockquote', ejecutar: () => editor.value?.chain().focus().toggleBlockquote().run() },
] as const;

function estaActivo(nombre: string, args?: Record<string, unknown>): boolean {
    return editor.value?.isActive(nombre, args) ?? false;
}
</script>

<template>
    <div class="overflow-hidden rounded-lg ring-1" :style="{ '--tw-ring-color': 'var(--color-borde)' }">
        <div
            v-if="editor"
            class="flex flex-wrap items-center gap-1 border-b px-2 py-1.5"
            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }"
        >
            <button
                v-for="accion in acciones"
                :key="accion.titulo"
                type="button"
                :title="accion.titulo"
                class="rounded px-2 py-1 text-xs font-semibold"
                :style="
                    estaActivo(accion.activo, (accion as any).args)
                        ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                        : { color: 'var(--color-texto)' }
                "
                @click="accion.ejecutar"
            >
                {{ accion.icono }}
            </button>
        </div>

        <EditorContent :editor="editor" />
    </div>
</template>

<style scoped>
.editor-prosa :deep(h2) {
    font-size: 1.15rem;
    font-weight: 700;
    margin: 0.6rem 0 0.3rem;
}
.editor-prosa :deep(h3) {
    font-size: 1rem;
    font-weight: 600;
    margin: 0.5rem 0 0.25rem;
}
.editor-prosa :deep(p) {
    margin: 0.35rem 0;
}
.editor-prosa :deep(ul) {
    list-style: disc;
    padding-left: 1.4rem;
    margin: 0.35rem 0;
}
.editor-prosa :deep(ol) {
    list-style: decimal;
    padding-left: 1.4rem;
    margin: 0.35rem 0;
}
.editor-prosa :deep(blockquote) {
    border-left: 3px solid var(--color-borde);
    padding-left: 0.75rem;
    color: var(--color-suave);
    margin: 0.4rem 0;
}
</style>
