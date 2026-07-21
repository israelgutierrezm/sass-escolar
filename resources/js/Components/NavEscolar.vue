<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const secciones = [
    { etiqueta: 'Ciclos', url: '/escolar/ciclos' },
    { etiqueta: 'Grupos', url: '/escolar/grupos' },
    { etiqueta: 'Inscripciones', url: '/escolar/inscripciones' },
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
