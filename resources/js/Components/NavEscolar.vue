<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
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
            { etiqueta: 'Inscripciones', url: '/escolar/inscripciones', permiso: 'inscribir-alumnos' },
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
</script>

<template>
    <!-- Una sola pestaña no es una navegación: se omite. -->
    <nav
        v-if="visibles.length > 1"
        class="flex flex-wrap gap-1 border-b pb-3"
        :style="{ borderColor: 'var(--color-borde)' }"
    >
        <a
            v-for="seccion in visibles"
            :key="seccion.url"
            :href="seccion.url"
            class="rounded-lg px-3 py-1.5 text-sm transition"
            :style="
                activa === seccion.url
                    ? {
                          backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)',
                          color: 'var(--color-acento)',
                          fontWeight: 500,
                      }
                    : { color: 'var(--color-suave)' }
            "
        >
            {{ seccion.etiqueta }}
        </a>
    </nav>
</template>
