<script setup lang="ts">
/**
 * El portal del supervisor externo: sus practicantes.
 *
 * Sólo lo suyo, y sólo lo que puede hacer: aprobar horas y revisar informes.
 * La lista la acota el servidor por sus expedientes asignados con acceso
 * vigente, así que aquí no hay que filtrar por nadie.
 */
import { Head, Link } from '@inertiajs/vue3';

import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Expediente {
    id: number;
    alumno: string | null;
    programa: string | null;
    tipo: string | null;
    organizacion: string | null;
    estado: string;
    estado_texto: string;
    estado_color: string;
    horas_por_revisar: number;
    informes_por_revisar: number;
}

defineProps<{ expedientes: Expediente[] }>();
</script>

<template>
    <Head title="Mis practicantes" />

    <AppLayout titulo="Mis practicantes">
        <div class="mx-auto max-w-5xl">
            <p class="mb-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                Aquí están las personas que supervisas. Puedes aprobar sus horas y revisar sus informes;
                no verás calificaciones ni estados de cuenta.
            </p>

            <div v-if="expedientes.length" class="overflow-x-auto rounded-xl border" :style="{ borderColor: 'var(--color-borde)' }">
                <table class="min-w-full text-sm">
                    <thead :style="{ backgroundColor: 'var(--color-fondo-suave)' }">
                        <tr class="text-left">
                            <th class="px-4 py-2 font-medium">Practicante</th>
                            <th class="px-4 py-2 font-medium">Proceso</th>
                            <th class="px-4 py-2 font-medium">Organización</th>
                            <th class="px-4 py-2 font-medium">Estado</th>
                            <th class="px-4 py-2 font-medium">Pendiente</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in expedientes"
                            :key="e.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ e.alumno }}</span>
                                <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ e.programa }}</span>
                            </td>
                            <td class="px-4 py-2">{{ e.tipo }}</td>
                            <td class="px-4 py-2">{{ e.organizacion }}</td>
                            <td class="px-4 py-2"><PildoraEstado :texto="e.estado_texto" :color="e.estado_color" sin-capitalizar /></td>
                            <td class="px-4 py-2">
                                <span v-if="e.horas_por_revisar" class="mr-2 rounded-full px-2 py-0.5 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, #0284c7 14%, transparent)', color: '#0284c7' }">
                                    {{ e.horas_por_revisar }} jornada(s)
                                </span>
                                <span v-if="e.informes_por_revisar" class="rounded-full px-2 py-0.5 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, #7c3aed 14%, transparent)', color: '#7c3aed' }">
                                    {{ e.informes_por_revisar }} informe(s)
                                </span>
                                <span v-if="!e.horas_por_revisar && !e.informes_por_revisar" class="text-xs" :style="{ color: 'var(--color-suave)' }">Al día</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Link :href="`/supervision/${e.id}`" class="text-sm underline">Abrir</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="rounded-xl border border-dashed px-6 py-10 text-center text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                Todavía no tienes practicantes asignados. Cuando la escuela te asigne uno, aparecerá aquí.
            </p>
        </div>
    </AppLayout>
</template>
