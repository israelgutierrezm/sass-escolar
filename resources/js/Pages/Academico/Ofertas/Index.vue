<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Oferta {
    id: number;
    carrera: string | null;
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
        : 'Aún no hay oferta registrada. Necesitas al menos una carrera, un plan y un campus.',
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
        <NavAcademico />

        <BarraListado
            v-model:vista="vista"
            url="/academico/ofertas"
            vista-clave="academico.ofertas"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por carrera o plan…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nueva oferta"
            nuevo-href="/academico/ofertas/create"
        />

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <TarjetaListado
                    v-for="oferta in ofertas.data"
                    :key="oferta.id"
                    :titulo="oferta.carrera ?? '—'"
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
                        <BotonAccion variante="editar" solo-icono :href="`/academico/ofertas/${oferta.id}/edit`" />
                        <BotonAccion variante="eliminar" solo-icono @click="eliminar(oferta.id)" />
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
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-4 py-3 font-medium">Carrera</th>
                            <th class="px-4 py-3 font-medium">Plan</th>
                            <th class="px-4 py-3 font-medium">Campus</th>
                            <th class="px-4 py-3 font-medium">Modalidad</th>
                            <th class="px-4 py-3 font-medium">Estatus</th>
                            <th class="px-4 py-3 font-medium">Alumnos</th>
                            <th class="px-4 py-3 font-medium text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="oferta in ofertas.data" :key="oferta.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3 font-medium">{{ oferta.carrera ?? '—' }}</td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                                {{ oferta.plan ?? '—' }}
                                <span class="block font-mono text-xs">{{ oferta.plan_clave }}</span>
                            </td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ oferta.campus ?? '—' }}</td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ oferta.modalidad ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-1 text-xs capitalize"
                                    :style="oferta.estatus === 'abierta'
                                        ? { backgroundColor: 'color-mix(in srgb, #16a34a 16%, transparent)' }
                                        : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                                >
                                    {{ oferta.estatus }}
                                </span>
                            </td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ oferta.matriculas_count }}</td>
                            <td class="px-4 py-3">
                                <div v-if="puedeEditar" class="flex justify-end gap-1">
                                    <BotonAccion variante="editar" solo-icono :href="`/academico/ofertas/${oferta.id}/edit`" />
                                    <BotonAccion variante="eliminar" solo-icono @click="eliminar(oferta.id)" />
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
