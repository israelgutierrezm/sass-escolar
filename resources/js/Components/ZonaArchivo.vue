<script setup lang="ts">
import { ref } from 'vue';

/**
 * Zona de carga de un archivo: arrastrar-y-soltar o clic. Mientras no hay
 * archivo muestra la zona con ícono; una vez cargado, una fila con el nombre y
 * un enlace para cambiarlo. Emite el `File` (o null); el padre decide qué hacer.
 */
defineProps<{
    accept: string;
    /** Texto principal de la zona (p. ej. «Arrastra el .cer…»). */
    texto: string;
    /** Nota secundaria opcional. */
    ayuda?: string;
    /** Etiqueta del archivo cargado (nombre, titular…); null = zona vacía. */
    cargado?: string | null;
    /** Muestra estado «procesando». */
    ocupado?: boolean;
}>();

const emit = defineEmits<{ archivo: [File | null] }>();

const arrastrando = ref(false);
const entrada = ref<HTMLInputElement | null>(null);

function abrir(): void {
    entrada.value?.click();
}

function alSeleccionar(evento: Event): void {
    emit('archivo', (evento.target as HTMLInputElement).files?.[0] ?? null);
}

function alSoltar(evento: DragEvent): void {
    arrastrando.value = false;
    emit('archivo', evento.dataTransfer?.files?.[0] ?? null);
}
</script>

<template>
    <div>
        <input ref="entrada" type="file" :accept="accept" class="hidden" @change="alSeleccionar" />

        <div
            v-if="!cargado"
            class="zona"
            :class="{ 'zona--activa': arrastrando }"
            role="button"
            tabindex="0"
            @click="abrir"
            @keydown.enter.prevent="abrir"
            @dragover.prevent="arrastrando = true"
            @dragenter.prevent="arrastrando = true"
            @dragleave.prevent="arrastrando = false"
            @drop.prevent="alSoltar"
        >
            <svg class="mx-auto h-9 w-9" :style="{ color: 'var(--color-acento)' }" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            <p class="mt-2 text-sm font-medium">
                <span v-if="ocupado">Procesando…</span>
                <span v-else>{{ texto }}</span>
            </p>
            <p v-if="ayuda" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ ayuda }}</p>
        </div>

        <div v-else class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
            <span class="flex min-w-0 items-center gap-2">
                <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                <span class="truncate">{{ cargado }}</span>
            </span>
            <button type="button" class="shrink-0 font-medium" :style="{ color: 'var(--color-acento)' }" @click="abrir">Cambiar</button>
        </div>
    </div>
</template>

<style scoped>
.zona {
    display: block;
    width: 100%;
    cursor: pointer;
    border-radius: 0.75rem;
    border: 2px dashed var(--color-borde);
    padding: 1.75rem 1rem;
    text-align: center;
    transition:
        border-color 0.15s ease,
        background-color 0.15s ease;
}

.zona:hover,
.zona:focus-visible,
.zona--activa {
    outline: none;
    border-color: var(--color-acento);
    background-color: color-mix(in srgb, var(--color-acento) 6%, transparent);
}
</style>
