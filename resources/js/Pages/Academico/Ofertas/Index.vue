<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

defineProps<{
    ofertas: {
        id: number;
        carrera: string | null;
        plan: string | null;
        plan_clave: string | null;
        campus: string | null;
        turno: string | null;
        modalidad: string;
        estatus: string;
        matriculas_count: number;
    }[];
    puedeEditar: boolean;
}>();

function eliminar(id: number): void {
    if (!confirm('¿Eliminar esta oferta?')) {
        return;
    }

    router.delete(`/academico/ofertas/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Oferta" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <p class="text-sm text-slate-500">
                    Qué se imparte y dónde. Es la unidad a la que se matriculan los alumnos.
                </p>
                <a
                    v-if="puedeEditar"
                    href="/academico/ofertas/create"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Nueva oferta
                </a>
            </div>

            <table v-if="ofertas.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Carrera</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Campus</th>
                        <th class="px-4 py-3 font-medium">Modalidad</th>
                        <th class="px-4 py-3 font-medium">Turno</th>
                        <th class="px-4 py-3 font-medium">Estatus</th>
                        <th class="px-4 py-3 font-medium">Alumnos</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="oferta in ofertas" :key="oferta.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-medium text-slate-800">{{ oferta.carrera ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ oferta.plan ?? '—' }}
                            <span class="block font-mono text-xs text-slate-400">{{ oferta.plan_clave }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ oferta.campus ?? '—' }}</td>
                        <td class="px-4 py-3 capitalize text-slate-600">{{ oferta.modalidad }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ oferta.turno ?? 'Sin turno' }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-1 text-xs capitalize"
                                :class="
                                    oferta.estatus === 'abierta'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-slate-100 text-slate-600'
                                "
                            >
                                {{ oferta.estatus }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ oferta.matriculas_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="puedeEditar">
                                <a
                                    :href="`/academico/ofertas/${oferta.id}/edit`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm text-slate-400 hover:text-red-600"
                                    @click="eliminar(oferta.id)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                Aún no hay oferta registrada. Necesitas al menos una carrera, un plan y un campus.
            </p>
        </div>
    </AppLayout>
</template>
