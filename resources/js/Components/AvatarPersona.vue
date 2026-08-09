<script setup lang="ts">
import { computed } from 'vue';

/**
 * La cara de una persona: su foto, o sus iniciales cuando no la hay.
 *
 * ── Por qué es un componente y no tres líneas en cada pantalla ─────────────
 * Estaba escrito a mano en diez sitios, y se fue separando: unas pantallas
 * sacaban DOS iniciales y otras una sola, el círculo era gris en usuarios y del
 * color de acento en tutorados, y los tamaños iban de 44 a 80 px sin criterio.
 * En una cuadrícula eso se nota de inmediato —la misma persona con dos caras
 * distintas según por dónde se entre—, que es justo lo que hacía que unos
 * listados parecieran de otro sistema.
 *
 * Aquí hay una sola forma: círculo con tinte del acento, foto recortada si la
 * hay, y dos iniciales si no.
 */
const props = withDefaults(
    defineProps<{
        nombre: string | null;
        foto?: string | null;
        /**
         * `sm` (40 px) para un renglón de tabla, `md` (48) para una tarjeta con
         * cuerpo —la cara acompaña al dato— y `lg` (80) para la que sólo
         * presenta a la persona, donde la cara ES el contenido.
         *
         * Tres y no siete: son los tres sitios donde una cara aparece. Cada vez
         * que alguien inventó un cuarto tamaño fue porque copió el marcado en
         * vez de usar esto.
         */
        tamano?: 'sm' | 'md' | 'lg';
    }>(),
    { tamano: 'md' },
);

/**
 * Dos iniciales: nombre y primer apellido.
 *
 * Con una sola, media escuela comparte letra y el círculo deja de identificar
 * a nadie. El punto es el último recurso —un registro sin nombre—: es más
 * discreto que una interrogación, que se lee como error.
 */
const iniciales = computed(() => {
    const partes = (props.nombre ?? '').trim().split(/\s+/).filter(Boolean);

    if (partes.length === 0) {
        return '·';
    }

    return (partes[0][0] + (partes[1]?.[0] ?? '')).toUpperCase();
});

const MEDIDAS = {
    sm: 'h-10 w-10 text-xs',
    md: 'h-12 w-12 text-sm',
    lg: 'h-20 w-20 text-xl',
} as const;

const medida = computed(() => MEDIDAS[props.tamano]);
</script>

<template>
    <img
        v-if="foto"
        :src="foto"
        :alt="nombre ?? ''"
        class="shrink-0 rounded-full object-cover ring-1 ring-black/5"
        :class="medida"
        loading="lazy"
    />
    <span
        v-else
        class="flex shrink-0 items-center justify-center rounded-full font-semibold ring-1 ring-black/5"
        :class="medida"
        :style="{
            backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
            color: 'var(--color-acento)',
        }"
        aria-hidden="true"
    >
        {{ iniciales }}
    </span>
</template>
