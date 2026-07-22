<script setup lang="ts">
import { computed } from 'vue';

/**
 * Tarjeta de presentación para la vista en cuadrícula.
 *
 * Sirve igual a un alumno y a un docente porque lo que cambia entre ellos son
 * los datos secundarios, no la forma: cara, nombre, un identificador y un par
 * de líneas de contexto.
 */
const props = defineProps<{
    nombre: string | null;
    /** Matrícula, clave de profesor… lo que identifica a esa persona en su rol. */
    identificador?: string | null;
    foto?: string | null;
    /** Líneas de contexto: carrera, campus, tipo… */
    lineas?: (string | null)[];
    /** Etiqueta de estado, con su color. */
    estado?: string | null;
    colorEstado?: string;
    url: string;
    /** Aviso que amerita atención (documentos por revisar, adeudo…). */
    aviso?: string | null;
    atenuada?: boolean;
}>();

/**
 * Iniciales como respaldo cuando no hay foto: es más reconocible que un
 * icono genérico repetido en toda la cuadrícula.
 */
const iniciales = computed(() => {
    const partes = (props.nombre ?? '').trim().split(/\s+/).filter(Boolean);

    if (partes.length === 0) {
        return '·';
    }

    return (partes[0][0] + (partes[1]?.[0] ?? '')).toUpperCase();
});

const visibles = computed(() => (props.lineas ?? []).filter((l): l is string => !!l));
</script>

<template>
    <a
        :href="url"
        class="tarjeta flex flex-col items-center p-5 text-center transition hover:shadow-md"
        :class="atenuada ? 'opacity-60' : ''"
    >
        <img
            v-if="foto"
            :src="foto"
            :alt="nombre ?? ''"
            class="h-20 w-20 rounded-full object-cover"
            loading="lazy"
        />
        <span
            v-else
            class="flex h-20 w-20 items-center justify-center rounded-full text-xl font-semibold"
            :style="{
                backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                color: 'var(--color-acento)',
            }"
            aria-hidden="true"
        >
            {{ iniciales }}
        </span>

        <p class="mt-3 font-medium leading-tight">{{ nombre }}</p>
        <p v-if="identificador" class="mt-0.5 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
            {{ identificador }}
        </p>

        <p
            v-for="(linea, i) in visibles"
            :key="i"
            class="mt-1 text-xs"
            :style="{ color: 'var(--color-suave)' }"
        >
            {{ linea }}
        </p>

        <span
            v-if="estado"
            class="mt-3 rounded-full px-2 py-0.5 text-xs capitalize"
            :style="{ backgroundColor: colorEstado ?? 'color-mix(in srgb, currentColor 10%, transparent)' }"
        >
            {{ estado }}
        </span>

        <span
            v-if="aviso"
            class="mt-2 rounded-full px-2 py-0.5 text-xs"
            style="background-color: color-mix(in srgb, #f59e0b 20%, transparent)"
        >
            {{ aviso }}
        </span>
    </a>
</template>
