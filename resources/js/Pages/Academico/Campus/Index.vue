<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Sede {
    id: number;
    clave: string;
    nombre: string;
    institucion: string | null;
    tipo: string | null;
    entidad: string | null;
    online: boolean;
    ofertas_count: number;
}

const props = defineProps<{
    campus: {
        data: Sede[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    instituciones: { id: number; nombre: string }[];
    tiposCampus: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = computed(() => [
    { clave: 'institucion_id', etiqueta: 'Institución', opciones: props.instituciones.map((i) => ({ valor: i.id, texto: i.nombre })) },
    { clave: 'tipo_campus_id', etiqueta: 'Tipo de campus', opciones: props.tiposCampus.map((t) => ({ valor: t.id, texto: t.nombre })) },
]);

const vacio = computed(() => !props.campus.data.length);
const mensajeVacio = computed(() =>
    props.filtros.busqueda || props.filtros.institucion_id || props.filtros.tipo_campus_id
        ? 'Ningún campus coincide con la búsqueda.'
        : 'Aún no hay campus registrados.',
);

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
            v-model:vista="vista"
            url="/academico/campus"
            vista-clave="academico.campus"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave o nombre…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nuevo campus"
            nuevo-href="/academico/campus/create"
        />

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <TarjetaListado
                    v-for="sede in campus.data"
                    :key="sede.id"
                    :titulo="sede.nombre"
                    :clave="sede.clave"
                    :metas="[
                        { etiqueta: 'Institución', valor: sede.institucion },
                        { etiqueta: 'Tipo', valor: sede.tipo },
                        { etiqueta: 'Entidad', valor: sede.entidad },
                        { etiqueta: 'Oferta', valor: sede.ofertas_count },
                    ]"
                >
                    <template v-if="sede.online" #insignia>
                        <span class="shrink-0 rounded px-1.5 py-0.5 text-xs" style="background-color: color-mix(in srgb, #0ea5e9 16%, transparent)">
                            En línea
                        </span>
                    </template>
                    <template v-if="puedeEditar" #acciones>
                        <BotonAccion variante="editar" solo-icono :href="`/academico/campus/${sede.id}/edit`" />
                        <BotonAccion variante="eliminar" solo-icono @click="eliminar(sede.id, sede.nombre)" />
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ mensajeVacio }}
            </p>

            <section v-if="campus.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="campus.links" :total="campus.total" :desde="campus.from" :hasta="campus.to" />
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
                            <th class="px-4 py-3 font-medium">Institución</th>
                            <th class="px-4 py-3 font-medium">Tipo</th>
                            <th class="px-4 py-3 font-medium">Entidad</th>
                            <th class="px-4 py-3 font-medium">Oferta</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sede in campus.data" :key="sede.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
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
                    {{ mensajeVacio }}
                </p>
            </div>

            <Paginacion :enlaces="campus.links" :total="campus.total" :desde="campus.from" :hasta="campus.to" />
        </div>
    </AppLayout>
</template>
