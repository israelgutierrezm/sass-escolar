<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Plan {
    id: number;
    clave: string;
    nombre: string;
    carrera: string | null;
    periodo: string | null;
    rvoe: string;
    vigente: boolean;
    total_creditos: number;
    materias_count: number;
}

const props = defineProps<{
    planes: {
        data: Plan[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    carreras: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const ICONO_PLAN =
    'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z';

const definicionFiltros = computed(() => [
    { clave: 'carrera_id', etiqueta: 'Carrera', opciones: props.carreras.map((c) => ({ valor: c.id, texto: c.nombre })) },
    {
        clave: 'vigente',
        etiqueta: 'Vigencia',
        opciones: [
            { valor: 'si', texto: 'Vigentes' },
            { valor: 'no', texto: 'No vigentes' },
        ],
    },
]);

const vacio = computed(() => !props.planes.data.length);
const mensajeVacio = computed(() =>
    props.filtros.busqueda || props.filtros.carrera_id || props.filtros.vigente
        ? 'Ningún plan coincide con la búsqueda.'
        : 'Aún no hay planes de estudio. Primero registra una carrera.',
);

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

        <BarraListado
            v-model:vista="vista"
            url="/academico/planes"
            vista-clave="academico.planes"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave, nombre o RVOE…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nuevo plan"
            nuevo-href="/academico/planes/create"
            titulo="Planes de estudio"
            descripcion="Mallas curriculares por carrera"
            :icono="ICONO_PLAN"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ planes.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="cuadricula-listado">
                <TarjetaListado
                    v-for="plan in planes.data"
                    :key="plan.id"
                    :titulo="plan.nombre"
                    :clave="plan.clave"
                    :metas="[
                        { etiqueta: 'Carrera', valor: plan.carrera },
                        { etiqueta: 'Periodo', valor: plan.periodo },
                        { etiqueta: 'RVOE', valor: plan.rvoe },
                        { etiqueta: 'Créditos', valor: plan.total_creditos },
                        { etiqueta: 'Materias', valor: plan.materias_count },
                    ]"
                >
                    <template v-if="plan.vigente" #insignia>
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-xs" style="background-color: color-mix(in srgb, #16a34a 16%, transparent)">
                            Vigente
                        </span>
                    </template>
                    <template #acciones>
                        <BotonAccion redondo variante="ver" texto="Malla" :href="`/academico/planes/${plan.id}/materias`" />
                        <BotonAccion redondo v-if="puedeEditar" variante="editar" solo-icono :href="`/academico/planes/${plan.id}/edit`" />
                        <BotonAccion redondo v-if="puedeEditar" variante="eliminar" solo-icono @click="eliminar(plan.id, plan.nombre)" />
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ mensajeVacio }}
            </p>

            <section v-if="planes.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="planes.links" :total="planes.total" :desde="planes.from" :hasta="planes.to" />
            </section>
        </template>

        <!-- Lista -->
        <div v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="!vacio" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Plan</th>
                            <th class="px-4 py-3 font-semibold">Carrera</th>
                            <th class="px-4 py-3 font-semibold">RVOE</th>
                            <th class="px-4 py-3 font-semibold text-center">Créditos</th>
                            <th class="px-4 py-3 font-semibold text-center">Materias</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in planes.data" :key="plan.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <!-- Plan: nombre + clave + periodo -->
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ plan.nombre }}</span>
                                <span class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ plan.clave }}<template v-if="plan.periodo"> · {{ plan.periodo }}</template>
                                </span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ plan.carrera ?? '—' }}</td>
                            <td class="px-4 py-4">
                                <span class="inline-block rounded-md px-2 py-0.5 font-mono text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ plan.rvoe }}</span>
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums">{{ plan.total_creditos }}</td>
                            <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ plan.materias_count }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="plan.vigente ? 'Vigente' : 'No vigente'" :color="plan.vigente ? '#16a34a' : 'var(--color-suave)'" />
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end gap-1">
                                    <BotonAccion variante="ver" texto="Malla" :href="`/academico/planes/${plan.id}/materias`" />
                                    <BotonAccion v-if="puedeEditar" variante="editar" solo-icono :href="`/academico/planes/${plan.id}/edit`" />
                                    <BotonAccion v-if="puedeEditar" variante="eliminar" solo-icono @click="eliminar(plan.id, plan.nombre)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ mensajeVacio }}
                </p>
            </div>

            <Paginacion :enlaces="planes.links" :total="planes.total" :desde="planes.from" :hasta="planes.to" />
        </div>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
