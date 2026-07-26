<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

const props = defineProps<{
    carreras: {
        id: number;
        clave: string;
        nombre: string;
        nivel: string | null;
        clave_sat: string | null;
        planes_count: number;
    }[];
    filtros: Record<string, any>;
    niveles: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const definicionFiltros = computed(() => [
    { clave: 'nivel_estudios_id', etiqueta: 'Nivel de estudios', opciones: props.niveles.map((n) => ({ valor: n.id, texto: n.nombre })) },
]);

function eliminar(id: number, nombre: string): void {
    if (!confirm(`¿Eliminar la carrera "${nombre}"?`)) {
        return;
    }

    router.delete(`/academico/carreras/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Carreras" />

    <AppLayout titulo="Catálogo académico">
        <NavAcademico />

        <BarraListado
            url="/academico/carreras"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave, nombre o identificador…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nueva carrera"
            nuevo-href="/academico/carreras/create"
        />

        <div class="tarjeta overflow-hidden">
            <table v-if="carreras.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-4 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Nombre</th>
                        <th class="px-4 py-3 font-medium">Nivel</th>
                        <th class="px-4 py-3 font-medium">Clave SAT</th>
                        <th class="px-4 py-3 font-medium">Planes</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="carrera in carreras" :key="carrera.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ carrera.clave }}</td>
                        <td class="px-4 py-3 font-medium">{{ carrera.nombre }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ carrera.nivel ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ carrera.clave_sat ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ carrera.planes_count }}</td>
                        <td class="px-4 py-3">
                            <div v-if="puedeEditar" class="flex justify-end gap-1">
                                <BotonAccion variante="editar" solo-icono :href="`/academico/carreras/${carrera.id}/edit`" />
                                <BotonAccion variante="eliminar" solo-icono @click="eliminar(carrera.id, carrera.nombre)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ filtros.busqueda || filtros.nivel_estudios_id
                    ? 'Ninguna carrera coincide con la búsqueda.'
                    : 'Aún no hay carreras registradas.' }}
            </p>
        </div>
    </AppLayout>
</template>
