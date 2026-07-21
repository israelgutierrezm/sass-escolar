<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';

defineProps<{
    ciclos: {
        id: number;
        clave: string;
        nombre: string;
        campus: string | null;
        situacion: string | null;
        fecha_inicio: string | null;
        fecha_fin: string | null;
        inscripcion_abierta: boolean;
        grupos_count: number;
    }[];
    puedeEditar: boolean;
}>();

function eliminar(id: number, clave: string): void {
    if (!confirm(`¿Eliminar el ciclo "${clave}"?`)) {
        return;
    }

    router.delete(`/escolar/ciclos/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Ciclos" />

    <AppLayout titulo="Control escolar">
        <NavEscolar />

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between border-b border-slate-100 p-4">
                <p class="text-sm text-slate-500">
                    El ciclo define el periodo y sus ventanas: hasta cuándo se inscribe y se capturan
                    calificaciones.
                </p>
                <a
                    v-if="puedeEditar"
                    href="/escolar/ciclos/create"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                >
                    Nuevo ciclo
                </a>
            </div>

            <table v-if="ciclos.length" class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Campus</th>
                        <th class="px-4 py-3 font-medium">Periodo</th>
                        <th class="px-4 py-3 font-medium">Situación</th>
                        <th class="px-4 py-3 font-medium">Inscripción</th>
                        <th class="px-4 py-3 font-medium">Grupos</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="ciclo in ciclos" :key="ciclo.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ ciclo.clave }}</td>
                        <td class="px-4 py-3 font-medium text-slate-800">{{ ciclo.nombre }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ ciclo.campus ?? 'Todos (global)' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500">
                            {{ ciclo.fecha_inicio }} → {{ ciclo.fecha_fin }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ ciclo.situacion }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-1 text-xs"
                                :class="
                                    ciclo.inscripcion_abierta
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-slate-100 text-slate-500'
                                "
                            >
                                {{ ciclo.inscripcion_abierta ? 'Abierta' : 'Cerrada' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ ciclo.grupos_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="puedeEditar">
                                <a
                                    :href="`/escolar/ciclos/${ciclo.id}/edit`"
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                                >
                                    Editar
                                </a>
                                <button
                                    type="button"
                                    class="ml-3 text-sm text-slate-400 hover:text-red-600"
                                    @click="eliminar(ciclo.id, ciclo.clave)"
                                >
                                    Eliminar
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm text-slate-500">
                Aún no hay ciclos registrados.
            </p>
        </div>
    </AppLayout>
</template>
