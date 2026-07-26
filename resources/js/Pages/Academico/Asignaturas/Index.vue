<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Fila {
    id: number;
    clave: string;
    nombre: string;
    creditos: number;
    tipo: string | null;
    clasificacion: string | null;
    area: string | null;
    horas: number;
    planes_count: number;
}

const props = defineProps<{
    asignaturas: {
        data: Fila[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    tiposAsignatura: { id: number; nombre: string }[];
    clasificaciones: { id: number; nombre: string }[];
    areas: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const definicionFiltros = computed(() => [
    { clave: 'tipo_asignatura_id', etiqueta: 'Tipo', opciones: props.tiposAsignatura.map((t) => ({ valor: t.id, texto: t.nombre })) },
    { clave: 'clasificacion_id', etiqueta: 'Clasificación', opciones: props.clasificaciones.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'area_id', etiqueta: 'Área', opciones: props.areas.map((a) => ({ valor: a.id, texto: a.nombre })) },
]);

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar la asignatura "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/asignaturas/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Asignaturas" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <BarraListado
            url="/academico/asignaturas"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave o nombre…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nueva asignatura"
            nuevo-href="/academico/asignaturas/create"
        />

        <div class="tarjeta overflow-hidden">
            <table v-if="asignaturas.data.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Tipo</th>
                        <th class="px-4 py-3 font-medium">Clasificación</th>
                        <th class="px-4 py-3 font-medium">Créditos</th>
                        <th class="px-4 py-3 font-medium">Horas</th>
                        <th class="px-4 py-3 font-medium">Planes</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="asignatura in asignaturas.data" :key="asignatura.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ asignatura.clave }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ asignatura.nombre }}</span>
                            <span v-if="asignatura.area" class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ asignatura.area }}</span>
                        </td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ asignatura.tipo ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ asignatura.clasificacion ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ asignatura.creditos }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ asignatura.horas || '—' }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-1 text-xs"
                                :style="asignatura.planes_count
                                    ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }
                                    : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                            >
                                {{ asignatura.planes_count }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="puedeEditar" class="flex justify-end gap-1">
                                <BotonAccion variante="editar" solo-icono :href="`/academico/asignaturas/${asignatura.id}/edit`" />
                                <BotonAccion variante="eliminar" solo-icono @click="eliminar(asignatura.id, asignatura.nombre)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay asignaturas que coincidan con la búsqueda.
            </p>

            <Paginacion :enlaces="asignaturas.links" :total="asignaturas.total" :desde="asignaturas.from" :hasta="asignaturas.to" />
        </div>
    </AppLayout>
</template>
