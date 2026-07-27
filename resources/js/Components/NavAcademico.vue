<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Sub-navegación del catálogo académico. Las entidades se editan por separado
 * pero forman una sola sección conceptual: campus y carreras son la base, los
 * planes cuelgan de la carrera y la oferta las combina.
 *
 * En móvil las ocho secciones ocupaban media pantalla, así que ahí se colapsan
 * en un selector desplegable; en escritorio siguen como pestañas, con el activo
 * subrayado en el color de acento del tema (no un morado fijo).
 */
const page = usePage();

const secciones = [
    { etiqueta: 'Institución', url: '/academico/instituciones' },
    { etiqueta: 'Campus', url: '/academico/campus' },
    { etiqueta: 'Carreras', url: '/academico/carreras' },
    { etiqueta: 'Asignaturas', url: '/academico/asignaturas' },
    { etiqueta: 'Planes de estudio', url: '/academico/planes' },
    { etiqueta: 'Oferta', url: '/academico/ofertas' },
    { etiqueta: 'Evaluación', url: '/academico/plantillas' },
    { etiqueta: 'Catálogos', url: '/academico/catalogos' },
];

const actual = computed(() => page.url.split('?')[0]);

const esActiva = (url: string): boolean => actual.value.startsWith(url);

const seccionActual = computed(() => secciones.find((s) => esActiva(s.url))?.url ?? secciones[0].url);

function ir(url: string): void {
    if (url !== seccionActual.value) {
        router.visit(url);
    }
}
</script>

<template>
    <div class="mb-6">
        <!-- Móvil: selector desplegable -->
        <div class="sm:hidden">
            <label class="sr-only" for="nav-academico">Sección</label>
            <select
                id="nav-academico"
                class="w-full rounded-lg border px-3 py-2.5 text-sm font-medium"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                :value="seccionActual"
                @change="ir(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="s in secciones" :key="s.url" :value="s.url">{{ s.etiqueta }}</option>
            </select>
        </div>

        <!-- Escritorio: pestañas con subrayado del activo -->
        <nav class="hidden border-b sm:flex sm:flex-wrap" :style="{ borderColor: 'var(--color-borde)' }">
            <a
                v-for="seccion in secciones"
                :key="seccion.url"
                :href="seccion.url"
                class="tab relative px-3 py-2.5 text-sm transition-colors"
                :class="esActiva(seccion.url) ? 'tab-activa font-semibold' : ''"
                :style="{ color: esActiva(seccion.url) ? 'var(--color-acento)' : 'var(--color-suave)' }"
            >
                {{ seccion.etiqueta }}
                <span
                    v-if="esActiva(seccion.url)"
                    class="absolute inset-x-2 -bottom-px h-0.5 rounded-full"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                />
            </a>
        </nav>
    </div>
</template>

<style scoped>
/* Realce suave al pasar el cursor sobre una pestaña inactiva. */
.tab:not(.tab-activa):hover {
    color: var(--color-contenido) !important;
}
</style>
