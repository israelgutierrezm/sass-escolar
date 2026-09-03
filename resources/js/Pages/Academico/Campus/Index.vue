<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
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

const ICONO_CAMPUS =
    'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z';

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
        <PestanasSeccion />

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
            titulo="Campus"
            descripcion="Sedes de la institución"
            :icono="ICONO_CAMPUS"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ campus.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="cuadricula-listado">
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
                        <BotonAccion redondo variante="editar" solo-icono :href="`/academico/campus/${sede.id}/edit`" />
                        <BotonAccion redondo variante="eliminar" solo-icono @click="eliminar(sede.id, sede.nombre)" />
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
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Campus</th>
                            <th class="px-4 py-3 font-semibold">Institución</th>
                            <th class="px-4 py-3 font-semibold">Tipo</th>
                            <th class="px-4 py-3 font-semibold">Entidad</th>
                            <th class="px-4 py-3 font-semibold text-center">Oferta</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="sede in campus.data" :key="sede.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <!-- Campus: nombre + clave + En línea -->
                            <td class="px-6 py-4">
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold text-contenido">{{ sede.nombre }}</span>
                                    <span
                                        v-if="sede.online"
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :style="{ backgroundColor: 'color-mix(in srgb, #0ea5e9 16%, transparent)', color: '#0284c7' }"
                                    >En línea</span>
                                </span>
                                <span class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ sede.clave }}</span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ sede.institucion ?? '—' }}</td>
                            <td class="px-4 py-4">
                                <span v-if="sede.tipo" class="inline-block rounded-full px-2.5 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }">{{ sede.tipo }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ sede.entidad ?? '—' }}</td>
                            <td class="px-4 py-4 text-center">
                                <span class="inline-grid h-7 min-w-7 place-items-center rounded-full px-2 text-xs font-semibold" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ sede.ofertas_count }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end">
                                    <MenuAcciones
                                        :opciones="puedeEditar ? [
                                            { variante: 'editar', href: `/academico/campus/${sede.id}/edit` },
                                            { variante: 'eliminar', clave: 'eliminar' },
                                        ] : []"
                                        @elegir="eliminar(sede.id, sede.nombre)"
                                    />
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

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
