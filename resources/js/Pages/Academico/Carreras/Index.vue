<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Carrera {
    id: number;
    clave: string;
    nombre: string;
    nivel: string | null;
    planes_count: number;
}

const props = defineProps<{
    carreras: {
        data: Carrera[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    niveles: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = computed(() => [
    { clave: 'nivel_estudios_id', etiqueta: 'Nivel de estudios', opciones: props.niveles.map((n) => ({ valor: n.id, texto: n.nombre })) },
]);

const vacio = computed(() => !props.carreras.data.length);
const mensajeVacio = computed(() =>
    props.filtros.busqueda || props.filtros.nivel_estudios_id
        ? 'Ninguna carrera coincide con la búsqueda.'
        : 'Aún no hay carreras registradas.',
);

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
            v-model:vista="vista"
            url="/academico/carreras"
            vista-clave="academico.carreras"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave, nombre o identificador…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nueva carrera"
            nuevo-href="/academico/carreras/create"
        />

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <TarjetaListado
                    v-for="carrera in carreras.data"
                    :key="carrera.id"
                    :titulo="carrera.nombre"
                    :clave="carrera.clave"
                    :metas="[
                        { etiqueta: 'Nivel', valor: carrera.nivel },
                        { etiqueta: 'Planes', valor: carrera.planes_count },
                    ]"
                >
                    <template v-if="puedeEditar" #acciones>
                        <BotonAccion variante="editar" solo-icono :href="`/academico/carreras/${carrera.id}/edit`" />
                        <BotonAccion variante="eliminar" solo-icono @click="eliminar(carrera.id, carrera.nombre)" />
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ mensajeVacio }}
            </p>

            <section v-if="carreras.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="carreras.links" :total="carreras.total" :desde="carreras.from" :hasta="carreras.to" />
            </section>
        </template>

        <!-- Lista -->
        <div v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="!vacio" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-4 py-3 font-medium">Clave</th>
                            <th class="px-4 py-3 font-medium">Nombre</th>
                            <th class="px-4 py-3 font-medium">Nivel</th>
                            <th class="px-4 py-3 font-medium">Planes</th>
                            <th class="px-4 py-3 font-medium text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="carrera in carreras.data" :key="carrera.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ carrera.clave }}</td>
                            <td class="px-4 py-3 font-medium">{{ carrera.nombre }}</td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ carrera.nivel ?? '—' }}</td>
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
                    {{ mensajeVacio }}
                </p>
            </div>

            <Paginacion :enlaces="carreras.links" :total="carreras.total" :desde="carreras.from" :hasta="carreras.to" />
        </div>
    </AppLayout>
</template>
