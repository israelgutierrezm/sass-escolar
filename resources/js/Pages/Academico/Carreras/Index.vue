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

const ICONO_CARRERA =
    'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5';

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
            titulo="Carreras"
            descripcion="Programas educativos de la institución"
            :icono="ICONO_CARRERA"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ carreras.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="cuadricula-listado">
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
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Carrera</th>
                            <th class="px-4 py-3 font-semibold">Nivel</th>
                            <th class="px-4 py-3 font-semibold text-center">Planes</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="carrera in carreras.data" :key="carrera.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <!-- Carrera: nombre + clave -->
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ carrera.nombre }}</span>
                                <span class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ carrera.clave }}</span>
                            </td>
                            <td class="px-4 py-4">
                                <span v-if="carrera.nivel" class="inline-block rounded-full px-2.5 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }">{{ carrera.nivel }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="inline-grid h-7 min-w-7 place-items-center rounded-full px-2 text-xs font-semibold" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ carrera.planes_count }}</span>
                            </td>
                            <td class="px-6 py-4">
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

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
