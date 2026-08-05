<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PropsCompartidas } from '@/tipos';

/**
 * Pestañas de una sección.
 *
 * Antes traía una lista fija que incluía Alumnos y Docentes, y siguió
 * mostrándolos después de que subieran a secciones propias del menú: quedaban
 * dos caminos al mismo sitio y la pestaña activa mentía sobre dónde estabas.
 *
 * Ahora cada pantalla puede declarar sus pestañas y se filtran por PERMISO. El
 * filtro no es cosmético: la lista fija ofrecía «Inscripciones» a cualquiera
 * que llegara a control escolar, y quien no tuviera `inscribir-alumnos` se
 * comía un 403 al hacer clic en una pestaña que el sistema le había pintado.
 */
const props = withDefaults(
    defineProps<{
        secciones?: { etiqueta: string; url: string; permiso?: string | null }[];
    }>(),
    {
        secciones: () => [
            { etiqueta: 'Ciclos', url: '/escolar/ciclos', permiso: 'ver-grupos' },
            { etiqueta: 'Grupos', url: '/escolar/grupos', permiso: 'abrir-grupos' },
            // Inscribir es de las cosas que más se hacen al abrir un ciclo y
            // sólo se llegaba a ella entrando primero a un grupo.
            { etiqueta: 'Inscripción masiva', url: '/escolar/inscripciones/masiva', permiso: 'inscribir-alumnos' },
        ],
    },
);

const page = usePage<PropsCompartidas>();

const permisos = computed(() => page.props.auth.usuario?.permisos ?? []);

const visibles = computed(() =>
    props.secciones.filter((s) => !s.permiso || permisos.value.includes(s.permiso)),
);

const actual = computed(() => page.url.split('?')[0]);

// El más específico gana: sin esto, dos pestañas que compartan prefijo se
// marcarían las dos como activas.
const activa = computed(() => {
    const coincidencias = visibles.value
        .filter((s) => actual.value === s.url || actual.value.startsWith(s.url + '/'))
        .sort((a, b) => b.url.length - a.url.length);

    return coincidencias[0]?.url ?? null;
});

const seccionActual = computed(() => activa.value ?? visibles.value[0]?.url ?? '');

function ir(url: string): void {
    if (url !== seccionActual.value) {
        router.visit(url);
    }
}
</script>

<template>
    <!-- Una sola pestaña no es una navegación: se omite. -->
    <div v-if="visibles.length > 1" class="mb-6">
        <!-- Móvil: selector desplegable -->
        <div class="sm:hidden">
            <label class="sr-only" for="nav-escolar">Sección</label>
            <select
                id="nav-escolar"
                class="w-full rounded-lg border px-3 py-2.5 text-sm font-medium"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                :value="seccionActual"
                @change="ir(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="s in visibles" :key="s.url" :value="s.url">{{ s.etiqueta }}</option>
            </select>
        </div>

        <!-- Escritorio: pestañas con subrayado del activo -->
        <nav class="hidden border-b sm:flex sm:flex-wrap" :style="{ borderColor: 'var(--color-borde)' }">
            <a
                v-for="seccion in visibles"
                :key="seccion.url"
                :href="seccion.url"
                class="tab relative px-3 py-2.5 text-sm transition-colors"
                :class="activa === seccion.url ? 'tab-activa font-semibold' : ''"
                :style="{ color: activa === seccion.url ? 'var(--color-acento)' : 'var(--color-suave)' }"
            >
                {{ seccion.etiqueta }}
                <span
                    v-if="activa === seccion.url"
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
