<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

const props = defineProps<{
    campus: {
        id: number;
        clave: string;
        nombre: string;
        institucion: string | null;
        tipo: string | null;
        entidad: string | null;
        online: boolean;
        ofertas_count: number;
    }[];
    filtros: Record<string, any>;
    instituciones: { id: number; nombre: string }[];
    tiposCampus: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const definicionFiltros = computed(() => [
    { clave: 'institucion_id', etiqueta: 'Institución', opciones: props.instituciones.map((i) => ({ valor: i.id, texto: i.nombre })) },
    { clave: 'tipo_campus_id', etiqueta: 'Tipo de campus', opciones: props.tiposCampus.map((t) => ({ valor: t.id, texto: t.nombre })) },
]);

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar el campus "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/campus/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Campus" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <BarraListado
            url="/academico/campus"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave o nombre…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nuevo campus"
            nuevo-href="/academico/campus/create"
        />

        <div class="tarjeta overflow-hidden">
            <table v-if="campus.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Institución</th>
                        <th class="px-4 py-3 font-medium">Tipo</th>
                        <th class="px-4 py-3 font-medium">Entidad</th>
                        <th class="px-4 py-3 font-medium">Oferta</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="sede in campus" :key="sede.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ sede.clave }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ sede.nombre }}</span>
                            <span
                                v-if="sede.online"
                                class="ml-2 rounded px-1.5 py-0.5 text-xs"
                                style="background-color: color-mix(in srgb, #0ea5e9 16%, transparent)"
                            >
                                En línea
                            </span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ sede.institucion ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ sede.tipo ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ sede.entidad ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ sede.ofertas_count }}</td>
                        <td class="px-4 py-3">
                            <div v-if="puedeEditar" class="flex justify-end gap-1">
                                <BotonAccion variante="editar" solo-icono :href="`/academico/campus/${sede.id}/edit`" />
                                <BotonAccion variante="eliminar" solo-icono @click="eliminar(sede.id, sede.nombre)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ filtros.busqueda || filtros.institucion_id || filtros.tipo_campus_id
                    ? 'Ningún campus coincide con la búsqueda.'
                    : 'Aún no hay campus registrados.' }}
            </p>
        </div>
    </AppLayout>
</template>
