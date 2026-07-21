<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

defineProps<{
    planes: {
        id: number;
        clave: string;
        nombre: string;
        carrera: string | null;
        periodo: string | null;
        rvoe: string;
        vigente: boolean;
        total_creditos: number;
        materias_count: number;
    }[];
    puedeEditar: boolean;
}>();

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar el plan "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/planes/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Planes de estudio" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <p class="text-sm text-slate-500">
                    Una carrera puede tener varios planes; solo los vigentes reciben alumnos nuevos.
                </p>
                <a
                    v-if="puedeEditar"
                    href="/academico/planes/create"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Nuevo plan
                </a>
            </div>

            <table v-if="planes.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Carrera</th>
                        <th class="px-4 py-3 font-medium">Periodo</th>
                        <th class="px-4 py-3 font-medium">RVOE</th>
                        <th class="px-4 py-3 font-medium">Créditos</th>
                        <th class="px-4 py-3 font-medium">Materias</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="plan in planes" :key="plan.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ plan.clave }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-800">{{ plan.nombre }}</span>
                            <span
                                v-if="plan.vigente"
                                class="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700"
                            >
                                Vigente
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ plan.carrera ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ plan.periodo ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ plan.rvoe }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ plan.total_creditos }}</td>
                        <td class="px-4 py-3">
                            <a
                                :href="`/academico/planes/${plan.id}/materias`"
                                class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700 hover:bg-indigo-50 hover:text-indigo-700"
                            >
                                {{ plan.materias_count }} materia(s)
                            </a>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a
                                :href="`/academico/planes/${plan.id}/materias`"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                            >
                                Malla
                            </a>
                            <template v-if="puedeEditar">
                                <span class="mx-2 text-slate-200">|</span>
                                <a
                                    :href="`/academico/planes/${plan.id}/edit`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm text-slate-400 hover:text-red-600"
                                    @click="eliminar(plan.id, plan.nombre)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                Aún no hay planes de estudio. Primero registra una carrera.
            </p>
        </div>
    </AppLayout>
</template>
