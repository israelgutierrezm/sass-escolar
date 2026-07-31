<script setup lang="ts">
/**
 * Píldora de estado con punto de color: el patrón ESTÁNDAR para mostrar el
 * estatus/situación de un registro en tablas y en la vista cuadrícula.
 *
 * `color` es un color SÓLIDO (p. ej. '#16a34a', 'var(--color-acento)'). El texto
 * usa ese color y el fondo un tinte al 14 %; el punto va del color pleno. Sin
 * color se cae a un gris neutro.
 */
withDefaults(
    defineProps<{
        texto?: string | null;
        /** Color sólido del estado (texto + punto); el fondo es su tinte. */
        color?: string;
        /** Sin mayúscula inicial automática (para textos ya formateados). */
        sinCapitalizar?: boolean;
    }>(),
    { color: 'var(--color-suave)', sinCapitalizar: false },
);
</script>

<template>
    <span
        v-if="texto"
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
        :class="sinCapitalizar ? '' : 'capitalize'"
        :style="{ color, backgroundColor: `color-mix(in srgb, ${color} 14%, transparent)` }"
    >
        <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: color }" />
        {{ texto }}
    </span>
</template>
