<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

defineProps<{
    campus: {
        id: number;
        clave: string;
        nombre: string;
        tipo: string | null;
        entidad: string | null;
        online: boolean;
        ofertas_count: number;
    }[];
    puedeEditar: boolean;
}>();

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar el campus "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/campus/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Campus" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <p class="text-sm text-slate-500">Planteles donde la escuela imparte su oferta.</p>
                <a
                    v-if="puedeEditar"
                    href="/academico/campus/create"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Nuevo campus
                </a>
            </div>

            <table v-if="campus.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Tipo</th>
                        <th class="px-4 py-3 font-medium">Entidad</th>
                        <th class="px-4 py-3 font-medium">Oferta</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="sede in campus" :key="sede.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ sede.clave }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-800">{{ sede.nombre }}</span>
                            <span
                                v-if="sede.online"
                                class="ml-2 rounded bg-sky-100 px-1.5 py-0.5 text-xs text-sky-700"
                            >
                                En línea
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ sede.tipo ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ sede.entidad ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ sede.ofertas_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="puedeEditar">
                                <a
                                    :href="`/academico/campus/${sede.id}/edit`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm text-slate-400 hover:text-red-600"
                                    @click="eliminar(sede.id, sede.nombre)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                Aún no hay campus registrados.
            </p>
        </div>
    </AppLayout>
</template>
