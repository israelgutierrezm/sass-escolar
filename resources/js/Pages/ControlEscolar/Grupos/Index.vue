<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';

defineProps<{
    grupos: {
        id: number;
        clave: string;
        nombre: string | null;
        ciclo: string | null;
        campus: string | null;
        plan: string | null;
        turno: string | null;
        situacion: string | null;
        cupo: number | null;
        materias_count: number;
    }[];
    puedeEditar: boolean;
}>();

function eliminar(id: number, clave: string): void {
    if (!confirm(`¿Eliminar el grupo "${clave}"?`)) {
        return;
    }

    router.delete(`/escolar/grupos/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Grupos" />

    <AppLayout titulo="Control escolar">
        <NavEscolar />

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <p class="text-sm text-slate-500">
                    Contenedor de materias en un ciclo. Una materia solo es cursable si está abierta en un grupo.
                </p>
                <a
                    v-if="puedeEditar"
                    href="/escolar/grupos/create"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Nuevo grupo
                </a>
            </div>

            <table v-if="grupos.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Ciclo</th>
                        <th class="px-4 py-3 font-medium">Campus</th>
                        <th class="px-4 py-3 font-medium">Plan</th>
                        <th class="px-4 py-3 font-medium">Turno</th>
                        <th class="px-4 py-3 font-medium">Cupo</th>
                        <th class="px-4 py-3 font-medium">Materias</th>
                        <th class="px-4 py-3 font-medium">Situación</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="grupo in grupos" :key="grupo.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a
                                :href="`/escolar/grupos/${grupo.id}`"
                                class="font-mono text-xs font-medium text-indigo-600 hover:text-indigo-700"
                            >
                                {{ grupo.clave }}
                            </a>
                            <span v-if="grupo.nombre" class="block text-xs text-slate-400">{{ grupo.nombre }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ grupo.ciclo }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ grupo.campus }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ grupo.plan ?? 'Sin plan fijo' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ grupo.turno ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ grupo.cupo ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ grupo.materias_count }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ grupo.situacion }}</td>
                        <td class="px-4 py-3 text-right">
                            <a
                                :href="`/escolar/grupos/${grupo.id}`"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                            >
                                Abrir
                            </a>
                            <template v-if="puedeEditar">
                                <span class="mx-2 text-slate-200">|</span>
                                <a
                                    :href="`/escolar/grupos/${grupo.id}/edit`"
                                    class="text-sm text-slate-500 hover:text-slate-700"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm text-slate-400 hover:text-red-600"
                                    @click="eliminar(grupo.id, grupo.clave)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                Aún no hay grupos. Necesitas al menos un ciclo y un campus.
            </p>
        </div>
    </AppLayout>
</template>
