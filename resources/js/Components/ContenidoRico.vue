<script setup lang="ts">
/**
 * Pinta el HTML que salió de un editor.
 *
 * ── Sobre el `v-html` ──────────────────────────────────────────────────────
 * Es la única forma de mostrar contenido con formato, y es segura aquí porque
 * lo que llega ya pasó por `App\Support\HtmlSeguro` en el servidor: lista
 * blanca de etiquetas y atributos, sin `on*` ni `javascript:`. El saneado va
 * ahí y no aquí a propósito —el servidor es lo único que no se puede saltar—.
 *
 * ── El ancho de lectura ────────────────────────────────────────────────────
 * Va en cada bloque de texto y no en el contenedor: una imagen no tiene por
 * qué encogerse a la medida del párrafo, y un diagrama apretado en una columna
 * corta cuando al lado sobra espacio se lee peor que uno a ancho completo.
 */
withDefaults(defineProps<{ html: string | null; compacto?: boolean }>(), { compacto: false });
</script>

<template>
    <div v-if="html" class="prosa" :class="{ 'prosa-compacta': compacto }" v-html="html" />
</template>

<style scoped>
.prosa {
    color: var(--color-contenido);
    font-size: 0.925rem;
    line-height: 1.7;
}

.prosa :deep(p),
.prosa :deep(ul),
.prosa :deep(ol),
.prosa :deep(blockquote),
.prosa :deep(h1),
.prosa :deep(h2),
.prosa :deep(h3),
.prosa :deep(h4) {
    max-width: 68ch;
}

.prosa :deep(p) { margin-bottom: 0.85em; }
.prosa :deep(p:last-child) { margin-bottom: 0; }

.prosa :deep(h1),
.prosa :deep(h2),
.prosa :deep(h3),
.prosa :deep(h4) {
    font-weight: 600;
    line-height: 1.3;
    margin-top: 1.4em;
    margin-bottom: 0.4em;
}

.prosa :deep(h1) { font-size: 1.35rem; }
.prosa :deep(h2) { font-size: 1.15rem; }
.prosa :deep(h3) { font-size: 1.05rem; }

.prosa :deep(h1:first-child),
.prosa :deep(h2:first-child),
.prosa :deep(h3:first-child) { margin-top: 0; }

.prosa :deep(ul),
.prosa :deep(ol) { margin: 0 0 0.85em 1.4em; }

.prosa :deep(ul) { list-style: disc; }
.prosa :deep(ol) { list-style: decimal; }
.prosa :deep(li) { margin-bottom: 0.3em; }

.prosa :deep(blockquote) {
    border-left: 3px solid var(--color-acento);
    padding-left: 1rem;
    margin: 0 0 0.85em;
    color: var(--color-suave);
}

.prosa :deep(a) {
    color: var(--color-acento);
    text-decoration: underline;
}

.prosa :deep(hr) {
    border: 0;
    border-top: 1px solid var(--color-borde);
    margin: 1.2em 0;
}

/* La imagen nunca desborda su tarjeta, mida lo que mida el original. */
.prosa :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin: 0.5em 0;
}

.prosa :deep(table) {
    border-collapse: collapse;
    margin-bottom: 0.85em;
}

.prosa :deep(th),
.prosa :deep(td) {
    border: 1px solid var(--color-borde);
    padding: 0.4rem 0.6rem;
}

/*
 * En una tarjeta de listado el aviso se resume, no se lee entero: se recorta a
 * tres líneas para que diez avisos quepan en pantalla. Al abrirlo se ve todo.
 */
.prosa-compacta {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.prosa-compacta :deep(img),
.prosa-compacta :deep(iframe),
.prosa-compacta :deep(table) {
    display: none;
}
</style>
