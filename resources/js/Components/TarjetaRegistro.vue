<script setup lang="ts">
import { computed } from 'vue';

/**
 * Tarjeta de cuadrícula para lo que NO es una persona: un grupo, una factura,
 * un adeudo.
 *
 * Existe aparte de `TarjetaPersona` porque el reemplazo de la foto no aplica —
 * un grupo no tiene cara ni iniciales que reconocer— y lo que se lee de un
 * vistazo son pares dato/valor, no líneas sueltas de contexto.
 */
const props = defineProps<{
    /** Lo que identifica al registro: la clave del grupo, el folio… */
    titulo: string;
    subtitulo?: string | null;
    /** Pares que se muestran en rejilla; los de valor vacío se omiten. */
    datos?: { etiqueta: string; valor: string | number | null }[];
    estado?: string | null;
    colorEstado?: string;
    url: string;
}>();

const visibles = computed(() =>
    (props.datos ?? []).filter((d) => d.valor !== null && d.valor !== '' && d.valor !== undefined),
);
</script>

<template>
    <div class="tarjeta flex flex-col gap-3 p-5 transition hover:shadow-md">
        <div class="flex items-start justify-between gap-2">
            <div>
                <a :href="url" class="font-mono text-sm font-semibold" :style="{ color: 'var(--color-acento)' }">
                    {{ titulo }}
                </a>
                <p v-if="subtitulo" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ subtitulo }}
                </p>
            </div>
            <span
                v-if="estado"
                class="shrink-0 rounded-full px-2 py-0.5 text-xs"
                :style="{ backgroundColor: colorEstado ?? 'color-mix(in srgb, currentColor 10%, transparent)' }"
            >
                {{ estado }}
            </span>
        </div>

        <dl v-if="visibles.length" class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
            <div v-for="dato in visibles" :key="dato.etiqueta">
                <dt :style="{ color: 'var(--color-suave)' }">{{ dato.etiqueta }}</dt>
                <dd class="mt-0.5">{{ dato.valor }}</dd>
            </div>
        </dl>

        <!-- Acciones: opcionales, para que la tarjeta no obligue a entrar. -->
        <div v-if="$slots.acciones" class="mt-auto flex flex-wrap items-center gap-3 pt-1 text-sm">
            <slot name="acciones" />
        </div>
    </div>
</template>
