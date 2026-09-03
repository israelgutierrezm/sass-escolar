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
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Oferta {
    id: number;
    programa_academico: string | null;
    plan: string | null;
    plan_clave: string | null;
    campus: string | null;
    modalidad: string | null;
    estatus: string;
    matriculas_count: number;
}

const props = defineProps<{
    ofertas: {
        data: Oferta[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    campus: { id: number; nombre: string }[];
    modalidades: { clave: string; nombre: string }[];
    puedeEditar: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const ICONO_OFERTA =
    'M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122';

const definicionFiltros = computed(() => [
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'modalidad', etiqueta: 'Modalidad', opciones: props.modalidades.map((m) => ({ valor: m.clave, texto: m.nombre })) },
    {
        clave: 'estatus',
        etiqueta: 'Estatus',
        opciones: [
            { valor: 'abierta', texto: 'Abierta' },
            { valor: 'cerrada', texto: 'Cerrada' },
        ],
    },
]);

const vacio = computed(() => !props.ofertas.data.length);
const mensajeVacio = computed(() =>
    props.filtros.busqueda || props.filtros.campus_id || props.filtros.modalidad || props.filtros.estatus
        ? 'Ninguna oferta coincide con la búsqueda.'
        : 'Aún no hay oferta registrada. Necesitas al menos un programa académico, un plan y un campus.',
);

function eliminar(id: number): void {
    if (!confirm('¿Eliminar esta oferta?')) {
        return;
    }

    router.delete(`/academico/ofertas/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Oferta" />

    <AppLayout titulo="Catálogo académico">
        <PestanasSeccion />

        <BarraListado
            v-model:vista="vista"
            url="/academico/ofertas"
            vista-clave="academico.ofertas"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por programa académico o plan…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nueva oferta"
            nuevo-href="/academico/ofertas/create"
            titulo="Oferta educativa"
            descripcion="Programa académico + plan + campus abiertos a matrícula"
            :icono="ICONO_OFERTA"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ ofertas.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="cuadricula-listado">
                <TarjetaListado
                    v-for="oferta in ofertas.data"
                    :key="oferta.id"
                    :titulo="oferta.programaAcademico ?? '—'"
                    :clave="oferta.plan_clave"
                    :metas="[
                        { etiqueta: 'Plan', valor: oferta.plan },
                        { etiqueta: 'Campus', valor: oferta.campus },
                        { etiqueta: 'Modalidad', valor: oferta.modalidad ?? '—' },
                        { etiqueta: 'Alumnos', valor: oferta.matriculas_count },
                    ]"
                >
                    <template #insignia>
                        <span
                            class="shrink-0 rounded-full px-2 py-1 text-xs capitalize"
                            :style="oferta.estatus === 'abierta'
                                ? { backgroundColor: 'color-mix(in srgb, #16a34a 16%, transparent)' }
                                : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                        >
                            {{ oferta.estatus }}
                        </span>
                    </template>
                    <template v-if="puedeEditar" #acciones>
                        <BotonAccion redondo variante="editar" solo-icono :href="`/academico/ofertas/${oferta.id}/edit`" />
                        <BotonAccion redondo variante="eliminar" solo-icono @click="eliminar(oferta.id)" />
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ mensajeVacio }}
            </p>

            <section v-if="ofertas.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="ofertas.links" :total="ofertas.total" :desde="ofertas.from" :hasta="ofertas.to" />
            </section>
        </template>

        <!-- Lista -->
        <div v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="!vacio" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Programa académico / Plan</th>
                            <th class="px-4 py-3 font-semibold">Campus</th>
                            <th class="px-4 py-3 font-semibold">Modalidad</th>
                            <th class="px-4 py-3 font-semibold text-center">Alumnos</th>
                            <th class="px-4 py-3 font-semibold">Estatus</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="oferta in ofertas.data" :key="oferta.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <!-- Programa académico + plan -->
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ oferta.programaAcademico ?? '—' }}</span>
                                <span class="mt-1 block text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ oferta.plan ?? '—' }}<template v-if="oferta.plan_clave"> · <span class="font-mono">{{ oferta.plan_clave }}</span></template>
                                </span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ oferta.campus ?? '—' }}</td>
                            <td class="px-4 py-4">
                                <span v-if="oferta.modalidad" class="inline-block rounded-full px-2.5 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }">{{ oferta.modalidad }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ oferta.matriculas_count }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="oferta.estatus" :color="oferta.estatus === 'abierta' ? '#16a34a' : 'var(--color-suave)'" />
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end">
                                    <MenuAcciones
                                        :opciones="puedeEditar ? [
                                            { variante: 'editar', href: `/academico/ofertas/${oferta.id}/edit` },
                                            { variante: 'eliminar', clave: 'eliminar' },
                                        ] : []"
                                        @elegir="eliminar(oferta.id)"
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

            <Paginacion :enlaces="ofertas.links" :total="ofertas.total" :desde="ofertas.from" :hasta="ofertas.to" />
        </div>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
