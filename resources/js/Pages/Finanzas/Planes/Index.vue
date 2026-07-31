<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import BotonExpediente from '@/Components/BotonExpediente.vue';

interface Plan {
    id: number;
    nombre: string;
    ciclo: string | null;
    campus: string[];
    carreras: string[];
    conceptos: number;
    alumnos: number;
    aplica_recargos: boolean;
    afecta_estatus_deudor: boolean;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    vigente: boolean;
}

defineProps<{ planes: Plan[] }>();
</script>

<template>
    <Head title="Planes de cobro" />

    <AppLayout titulo="Planes de cobro">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">El motor de cobro, configurado</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Un plan vive en un <strong>ciclo</strong> y aplica a los campus y carreras que le
                        marques. Sus conceptos son cargos con fecha: una colegiatura se captura por rango y se
                        expande sola. Vincular el plan a un alumno es lo que le genera los cargos.
                    </p>
                </div>

                <BotonAccion variante="nuevo" texto="Nuevo plan" href="/finanzas/planes/nuevo" />
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="planes.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Plan</th>
                            <th class="px-4 py-3 font-semibold">Alcance</th>
                            <th class="px-4 py-3 font-semibold text-center">Conceptos</th>
                            <th class="px-4 py-3 font-semibold text-center">Alumnos</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in planes" :key="plan.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ plan.nombre }}</span>
                                <span class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ plan.ciclo ?? 'sin ciclo' }} · {{ plan.vigente_desde }} → {{ plan.vigente_hasta ?? 'sin fin' }}
                                </span>
                            </td>

                            <td class="px-4 py-4">
                                <span class="flex flex-wrap gap-1">
                                    <span
                                        v-for="c in plan.campus.slice(0, 2)"
                                        :key="c"
                                        class="rounded-full px-2 py-0.5 text-[11px]"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                    >{{ c }}</span>
                                    <span v-if="plan.campus.length > 2" class="text-[11px]" :style="{ color: 'var(--color-suave)' }">+{{ plan.campus.length - 2 }}</span>
                                </span>
                                <span class="mt-1 block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ plan.carreras.length ? `${plan.carreras.length} carrera(s)` : 'todas las carreras' }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-center">
                                <span
                                    class="inline-grid h-7 min-w-7 place-items-center rounded-full px-2 text-xs font-semibold"
                                    :style="plan.conceptos === 0
                                        ? { backgroundColor: 'color-mix(in srgb, #f59e0b 18%, transparent)', color: '#b45309' }
                                        : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }"
                                    :title="plan.conceptos === 0 ? 'Sin conceptos: no cobra nada' : ''"
                                >{{ plan.conceptos }}</span>
                            </td>

                            <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ plan.alumnos }}</td>

                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-1">
                                    <PildoraEstado :texto="plan.vigente ? 'Vigente' : 'Fuera de vigencia'" :color="plan.vigente ? '#16a34a' : 'var(--color-suave)'" />
                                    <PildoraEstado v-if="plan.aplica_recargos" texto="Recargos" color="#d97706" />
                                    <PildoraEstado v-if="plan.afecta_estatus_deudor" texto="Deudor" color="#dc2626" />
                                </div>
                            </td>

                            <td class="px-6 py-4 text-right">
                                <BotonExpediente :href="`/finanzas/planes/${plan.id}`" texto="Configurar" />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay planes de cobro. Sin al menos uno, generar cargos no produce nada.
                </p>
            </div>
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
