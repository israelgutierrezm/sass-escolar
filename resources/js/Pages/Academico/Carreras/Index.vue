<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

defineProps<{
    carreras: {
        id: number;
        clave: string;
        nombre: string;
        nivel: string | null;
        clave_sat: string | null;
        planes_count: number;
    }[];
    puedeEditar: boolean;
}>();

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar la carrera "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/carreras/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Carreras" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <p class="text-sm text-slate-500">Programas que ofrece la escuela.</p>
                <a
                    v-if="puedeEditar"
                    href="/academico/carreras/create"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Nueva carrera
                </a>
            </div>

            <table v-if="carreras.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Nivel</th>
                        <th class="px-4 py-3 font-medium">Clave SAT</th>
                        <th class="px-4 py-3 font-medium">Planes</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="carrera in carreras" :key="carrera.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ carrera.clave }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ carrera.nombre }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ carrera.nivel ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ carrera.clave_sat ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ carrera.planes_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="puedeEditar">
                                <a
                                    :href="`/academico/carreras/${carrera.id}/edit`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm text-slate-400 hover:text-red-600"
                                    @click="eliminar(carrera.id, carrera.nombre)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                Aún no hay carreras registradas.
            </p>
        </div>
    </AppLayout>
</template>
