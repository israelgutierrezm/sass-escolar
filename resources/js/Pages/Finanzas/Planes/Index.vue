<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import BotonExpediente from '@/Components/BotonExpediente.vue';
import BarraListado from '@/Components/BarraListado.vue';
import { ICONOS } from '@/iconos';
import { computed } from 'vue';

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
    puede_eliminar: boolean;
    motivo_no_eliminar: string | null;
}

const props = defineProps<{
    planes: Plan[];
    filtros: { busqueda: string; ciclo_id: string | null; vigentes: string | null };
    ciclos: { id: number; nombre: string }[];
}>();

/*
 * Los planes de ciclos pasados se quedan —son la historia de lo que se cobró— y
 * al cabo de unos años entierran a los tres que están en uso.
 */
const definicionFiltros = computed(() => [
    {
        clave: 'ciclo_id',
        etiqueta: 'Ciclo',
        opciones: props.ciclos.map((c) => ({ valor: c.id, texto: c.nombre })),
    },
    { clave: 'vigentes', etiqueta: 'Solo vigentes', tipo: 'booleano' as const },
]);

function eliminar(plan: Plan): void {
    if (!confirm(`¿Eliminar el plan "${plan.nombre}"? Esta acción no se puede deshacer.`)) return;
    router.delete(`/finanzas/planes/${plan.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Planes de cobro" />

    <AppLayout titulo="Planes de cobro">
        <BarraListado
            url="/finanzas/planes"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por nombre del plan…"
            titulo="El motor de cobro, configurado"
            descripcion="Un plan vive en un ciclo y aplica a los campus y carreras que le marques. Sus conceptos son cargos con fecha: una colegiatura se captura por rango y se expande sola. Vincular el plan a un alumno es lo que le genera los cargos."
            :icono="ICONOS.dinero"
            puede-crear
            nuevo-texto="Nuevo plan"
            nuevo-href="/finanzas/planes/nuevo"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ planes.length }} {{ planes.length === 1 ? 'plan' : 'planes' }}
                </span>
            </template>
        </BarraListado>

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

                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <BotonExpediente :href="`/finanzas/planes/${plan.id}`" texto="Configurar" />
                                    <BotonAccion
                                        v-if="plan.puede_eliminar"
                                        variante="eliminar"
                                        solo-icono
                                        @click="eliminar(plan)"
                                    />
                                    <!-- Un plan que ya cobró es historial financiero: se le pone
                                         fecha de fin, no se borra. -->
                                    <span
                                        v-else
                                        class="text-[11px]"
                                        :style="{ color: 'var(--color-suave)' }"
                                        :title="plan.motivo_no_eliminar ?? ''"
                                    >En uso</span>
                                </div>
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
