<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
import Paginacion from '@/Components/Paginacion.vue';

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
        />

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
                        <BotonAccion variante="ver" texto="Malla" :href="`/academico/planes/${plan.id}/materias`" />
                        <BotonAccion v-if="puedeEditar" variante="editar" solo-icono :href="`/academico/planes/${plan.id}/edit`" />
                        <BotonAccion v-if="puedeEditar" variante="eliminar" solo-icono @click="eliminar(plan.id, plan.nombre)" />
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
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-4 py-3 font-medium">Clave</th>
                            <th class="px-4 py-3 font-medium">Plan</th>
                            <th class="px-4 py-3 font-medium">Carrera</th>
                            <th class="px-4 py-3 font-medium">Periodo</th>
                            <th class="px-4 py-3 font-medium">RVOE</th>
                            <th class="px-4 py-3 font-medium">Créditos</th>
                            <th class="px-4 py-3 font-medium">Materias</th>
                            <th class="px-4 py-3 font-medium text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in planes.data" :key="plan.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ plan.clave }}</td>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ plan.nombre }}</span>
                                <span
                                    v-if="plan.vigente"
                                    class="ml-2 rounded px-1.5 py-0.5 text-xs"
                                    style="background-color: color-mix(in srgb, #16a34a 16%, transparent)"
                                >
                                    Vigente
                                </span>
                            </td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ plan.carrera ?? '—' }}</td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ plan.periodo ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ plan.rvoe }}</td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ plan.total_creditos }}</td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ plan.materias_count }}</td>
                            <td class="px-4 py-3">
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
