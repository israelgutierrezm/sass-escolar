<script setup lang="ts">
import { onMounted, watch } from 'vue';

/**
 * Alterna entre lista y cuadrícula.
 *
 * La preferencia se recuerda por listado (`clave`) en localStorage: quien
 * revisa alumnos en cuadrícula normalmente los quiere así también mañana, y
 * quien compara datos prefiere la tabla siempre.
 */
const props = defineProps<{ clave: string }>();

const modelo = defineModel<'lista' | 'cuadricula'>({ default: 'lista' });

const almacen = () => `acadion.vista.${props.clave}`;

onMounted(() => {
    const guardada = localStorage.getItem(almacen());

    if (guardada === 'lista' || guardada === 'cuadricula') {
        modelo.value = guardada;
    }
});

watch(modelo, (valor) => localStorage.setItem(almacen(), valor));
</script>

<template>
    <div class="flex rounded-lg border p-0.5" :style="{ borderColor: 'var(--color-borde)' }">
        <button
            type="button"
            class="rounded-md p-1.5"
            :style="modelo === 'lista' ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-suave)' }"
            aria-label="Ver como lista"
            title="Lista"
            @click="modelo = 'lista'"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>
        <button
            type="button"
            class="rounded-md p-1.5"
            :style="modelo === 'cuadricula' ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-suave)' }"
            aria-label="Ver como cuadrícula"
            title="Cuadrícula"
            @click="modelo = 'cuadricula'"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
            </svg>
        </button>
    </div>
</template>
