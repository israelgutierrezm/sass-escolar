<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

const props = defineProps<{
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
    filtros: Record<string, any>;
    carreras: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

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
            url="/academico/planes"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave, nombre o RVOE…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nuevo plan"
            nuevo-href="/academico/planes/create"
        />

        <div class="tarjeta overflow-hidden">
            <table v-if="planes.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
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
                <tbody>
                    <tr v-for="plan in planes" :key="plan.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
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
                {{ filtros.busqueda || filtros.carrera_id || filtros.vigente
                    ? 'Ningún plan coincide con la búsqueda.'
                    : 'Aún no hay planes de estudio. Primero registra una carrera.' }}
            </p>
        </div>
    </AppLayout>
</template>
