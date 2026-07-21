<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface FilaAspirante {
    id: number;
    nombre_completo: string | null;
    curp: string | null;
    email: string | null;
    situacion: string | null;
    campus: string | null;
    oferta: string | null;
    origen: string | null;
    paso: number;
    validado_admin: boolean;
}

interface Paginacion<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    aspirantes: Paginacion<FilaAspirante>;
    filtros: { busqueda: string; situacion_id: number | string | null };
    situaciones: { id: number; nombre: string }[];
    puedeCrear: boolean;
    puedeEditar: boolean;
}>();

const busqueda = ref(props.filtros.busqueda);
const situacionId = ref(props.filtros.situacion_id ?? '');

let temporizador: ReturnType<typeof setTimeout> | undefined;

/** Se consulta al servidor tras una pausa, para no disparar una petición por tecla. */
watch([busqueda, situacionId], () => {
    clearTimeout(temporizador);

    temporizador = setTimeout(() => {
        router.get(
            '/aspirantes',
            { busqueda: busqueda.value, situacion_id: situacionId.value },
            { preserveState: true, replace: true },
        );
    }, 300);
});
</script>

<template>
    <Head title="Aspirantes" />

    <AppLayout titulo="Aspirantes">
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
                <input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar por nombre o CURP…"
                    class="min-w-64 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                />

                <select
                    v-model="situacionId"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                >
                    <option value="">Todas las situaciones</option>
                    <option v-for="situacion in situaciones" :key="situacion.id" :value="situacion.id">
                        {{ situacion.nombre }}
                    </option>
                </select>

                <a
                    v-if="puedeCrear"
                    href="/aspirantes/nuevo"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700"
                >
                    Nuevo aspirante
                </a>
            </div>

            <table v-if="aspirantes.data.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">CURP</th>
                        <th class="px-4 py-3 font-medium">Interés</th>
                        <th class="px-4 py-3 font-medium">Situación</th>
                        <th class="px-4 py-3 font-medium">Origen</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="aspirante in aspirantes.data" :key="aspirante.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a
                                :href="`/aspirantes/${aspirante.id}`"
                                class="font-medium text-indigo-600 hover:text-indigo-700"
                            >
                                {{ aspirante.nombre_completo }}
                            </a>
                            <span v-if="aspirante.email" class="block text-xs text-slate-500">
                                {{ aspirante.email }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">
                            {{ aspirante.curp ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ aspirante.oferta ?? '—' }}
                            <span v-if="aspirante.campus" class="block text-xs text-slate-400">
                                {{ aspirante.campus }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">
                                {{ aspirante.situacion }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ aspirante.origen ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a
                                v-if="puedeEditar"
                                :href="`/aspirantes/${aspirante.id}/editar`"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                            >
                                Editar
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                No hay aspirantes que coincidan con la búsqueda.
            </p>

            <div
                v-if="aspirantes.data.length"
                class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3"
            >
                <p class="text-xs text-slate-500">
                    Mostrando {{ aspirantes.from }}–{{ aspirantes.to }} de {{ aspirantes.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <component
                        :is="enlace.url ? 'a' : 'span'"
                        v-for="enlace in aspirantes.links"
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
