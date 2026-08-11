<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, inject, type ComputedRef } from 'vue';
import type { Ubicacion } from '@/menu/construir';

/**
 * Las pestañas de la sección donde está parada la pantalla.
 *
 * ── Salen del CATÁLOGO del menú, no de una lista propia ───────────────────
 * Tenían la suya escrita a mano con las ocho opciones de Académico, y al mover
 * Oferta, Evaluación y Catálogos a «Configuración» la barra lateral lo respetó
 * y estas pestañas siguieron mostrando las ocho: el mismo módulo con dos
 * organigramas distintos en la misma pantalla. Derivándolas, eso no puede
 * volver a pasar.
 *
 * Dentro de un subgrupo se muestran SUS opciones, no las de toda la sección:
 * quien entró a Configuración está trabajando en eso, y ofrecerle ahí las cinco
 * de arriba mezcla dos niveles en una sola fila.
 *
 * ── Y respetan el permiso ─────────────────────────────────────────────────
 * Se resuelven sobre el árbol ya filtrado por faceta y permiso, así que una
 * pestaña nunca lleva a una pantalla que esa persona no puede abrir.
 *
 * En móvil ocupaban media pantalla, así que ahí se colapsan en un desplegable.
 */
const page = usePage();

const rutaActual = computed(() => page.url.split('?')[0]);

/*
 * La ubicación la calcula el layout UNA vez y la reparte. Recalcularla aquí
 * significaría rearmar el árbol del menú en cada pantalla y, peor, arriesgarse a
 * que las pestañas y el encabezado dijeran cosas distintas de la misma página.
 */
const ubicacion = inject<ComputedRef<Ubicacion>>('ubicacion');

const secciones = computed(() => ubicacion?.value.hermanas ?? []);

const esActiva = (url: string): boolean => rutaActual.value.startsWith(url);

const seccionActual = computed(
    () => secciones.value.find((s) => esActiva(s.url))?.url ?? secciones.value[0]?.url ?? '',
);

function ir(url: string): void {
    if (url !== seccionActual.value) {
        router.visit(url);
    }
}
</script>

<template>
    <!-- Con una sola opción no hay entre qué elegir: la fila sobra. -->
    <div v-if="secciones.length > 1" class="mb-6">
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
