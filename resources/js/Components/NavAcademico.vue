<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Sub-navegación del catálogo académico. Las cuatro entidades se editan por
 * separado pero forman una sola sección conceptual: campus y carreras son la
 * base, los planes cuelgan de la carrera y la oferta las combina.
 */
const page = usePage();

const secciones = [
    { etiqueta: 'Campus', url: '/academico/campus' },
    { etiqueta: 'Carreras', url: '/academico/carreras' },
    { etiqueta: 'Asignaturas', url: '/academico/asignaturas' },
    { etiqueta: 'Planes de estudio', url: '/academico/planes' },
    { etiqueta: 'Evaluación', url: '/academico/plantillas' },
    { etiqueta: 'Oferta', url: '/academico/ofertas' },
];

const actual = computed(() => page.url.split('?')[0]);
</script>

<template>
    <nav class="flex flex-wrap gap-1 border-b border-slate-200 pb-3">
        <a
            v-for="seccion in secciones"
            :key="seccion.url"
            :href="seccion.url"
            class="rounded-lg px-3 py-1.5 text-sm transition"
            :class="
                actual.startsWith(seccion.url)
                    ? 'bg-indigo-50 font-medium text-indigo-700'
                    : 'text-slate-600 hover:bg-slate-100'
            "
        >
            {{ seccion.etiqueta }}
        </a>
    </nav>
</template>
