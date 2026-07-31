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
        class="tarjeta-persona tarjeta flex flex-col items-center p-5 text-center"
        :class="atenuada ? 'opacity-60' : ''"
    >
        <img
            v-if="foto"
            :src="foto"
            :alt="nombre ?? ''"
            class="h-20 w-20 rounded-full object-cover ring-1 ring-black/5"
            loading="lazy"
        />
        <span
            v-else
            class="flex h-20 w-20 items-center justify-center rounded-full text-xl font-semibold ring-1 ring-black/5"
            :style="{
                backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                color: 'var(--color-acento)',
            }"
            aria-hidden="true"
        >
            {{ iniciales }}
        </span>

        <p class="mt-3 font-medium leading-tight">{{ nombre }}</p>
        <span
            v-if="identificador"
            class="mt-1.5 inline-block rounded-md px-2 py-0.5 font-mono text-[11px]"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }"
        >
            {{ identificador }}
        </span>

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
            class="mt-3 rounded-full px-2 py-0.5 text-xs font-medium capitalize"
            :style="{ backgroundColor: colorEstado ?? 'color-mix(in srgb, currentColor 10%, transparent)' }"
        >
            {{ estado }}
        </span>

        <span
            v-if="aviso"
            class="mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
            :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 20%, transparent)', color: '#b45309' }"
        >
            <svg class="h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            {{ aviso }}
        </span>
    </a>
</template>

<style scoped>
/* Elevación sutil al pasar el cursor: transform clásico (las utilidades
   translate/scale de Tailwind no se honran en este entorno) + realce del borde
   con el color de acento. */
.tarjeta-persona {
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}
.tarjeta-persona:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 20px -8px color-mix(in srgb, var(--color-contenido) 25%, transparent);
    border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde));
}
</style>
