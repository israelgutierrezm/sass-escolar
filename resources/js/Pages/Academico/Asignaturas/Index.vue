<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

interface Fila {
    id: number;
    clave: string;
    nombre: string;
    creditos: number;
    tipo: string | null;
    clasificacion: string | null;
    area: string | null;
    horas: number;
    planes_count: number;
}

const props = defineProps<{
    asignaturas: {
        data: Fila[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: { busqueda: string };
    puedeEditar: boolean;
}>();

const busqueda = ref(props.filtros.busqueda);
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(busqueda, () => {
    clearTimeout(temporizador);

    temporizador = setTimeout(() => {
        router.get('/academico/asignaturas', { busqueda: busqueda.value }, { preserveState: true, replace: true });
    }, 300);
});

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar la asignatura "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/asignaturas/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Asignaturas" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
                <div class="flex-1">
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Buscar por nombre o clave…"
                        class="w-full min-w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                    />
                    <p class="mt-1 text-xs text-slate-400">
                        Catálogo compartido: la misma asignatura se reutiliza en varios planes.
                    </p>
                </div>
                <a
                    v-if="puedeEditar"
                    href="/academico/asignaturas/create"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Nueva asignatura
                </a>
            </div>

            <table v-if="asignaturas.data.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Tipo</th>
                        <th class="px-4 py-3 font-medium">Clasificación</th>
                        <th class="px-4 py-3 font-medium">Créditos</th>
                        <th class="px-4 py-3 font-medium">Horas</th>
                        <th class="px-4 py-3 font-medium">Planes</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="asignatura in asignaturas.data" :key="asignatura.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ asignatura.clave }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-800">{{ asignatura.nombre }}</span>
                            <span v-if="asignatura.area" class="block text-xs text-slate-400">
                                {{ asignatura.area }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ asignatura.tipo ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ asignatura.clasificacion ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ asignatura.creditos }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ asignatura.horas || '—' }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-1 text-xs"
                                :class="
                                    asignatura.planes_count
                                        ? 'bg-indigo-50 text-indigo-700'
                                        : 'bg-slate-100 text-slate-500'
                                "
                            >
                                {{ asignatura.planes_count }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="puedeEditar">
                                <a
                                    :href="`/academico/asignaturas/${asignatura.id}/edit`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm text-slate-400 hover:text-red-600"
                                    @click="eliminar(asignatura.id, asignatura.nombre)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                No hay asignaturas que coincidan con la búsqueda.
            </p>

            <div
                v-if="asignaturas.data.length"
                class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3"
            >
                <p class="text-xs text-slate-500">
                    Mostrando {{ asignaturas.from }}–{{ asignaturas.to }} de {{ asignaturas.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <component
                        :is="enlace.url ? 'a' : 'span'"
                        v-for="enlace in asignaturas.links"
                        :key="enlace.label"
                        :href="enlace.url ?? undefined"
                        class="rounded px-2.5 py-1 text-xs"
                        :class="
                            enlace.active
                                ? 'bg-indigo-600 text-white'
                                : enlace.url
                                  ? 'text-slate-600 hover:bg-slate-100'
                                  : 'text-slate-300'
                        "
                        v-html="enlace.label"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
