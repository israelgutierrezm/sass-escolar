<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

const ICONO_CICLO =
    'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5';

interface Ciclo {
    id: number;
    clave: string;
    nombre: string;
    niveles: string[];
    campus: string[];
    situacion: string | null;
    fecha_inicio: string | null;
    fecha_fin: string | null;
    inscripcion_abierta: boolean;
    grupos_count: number;
}

const props = defineProps<{
    ciclos: {
        data: Ciclo[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    puedeEditar: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const vacio = computed(() => !props.ciclos.data.length);
const mensajeVacio = computed(() =>
    props.filtros.busqueda ? 'Ningún ciclo coincide con la búsqueda.' : 'Aún no hay ciclos registrados.',
);

function eliminar(id: number, clave: string): void {
    if (!confirm(`¿Eliminar el ciclo "${clave}"?`)) {
        return;
    }

    router.delete(`/escolar/ciclos/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Ciclos" />

    <AppLayout titulo="Control escolar">
        <NavEscolar />

        <BarraListado
            v-model:vista="vista"
            url="/escolar/ciclos"
            vista-clave="ciclos"
            :valores="filtros"
            :filtros="[]"
            placeholder="Buscar por clave o nombre…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nuevo ciclo"
            nuevo-href="/escolar/ciclos/create"
            titulo="Ciclos escolares"
            descripcion="Periodos lectivos"
            :icono="ICONO_CICLO"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ ciclos.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <TarjetaListado
                    v-for="ciclo in ciclos.data"
                    :key="ciclo.id"
                    :titulo="ciclo.nombre"
                    :clave="ciclo.clave"
                    :metas="[
                        { etiqueta: 'Campus', valor: ciclo.campus.length ? ciclo.campus.join(', ') : 'Todos (global)' },
                        { etiqueta: 'Periodo', valor: `${ciclo.fecha_inicio ?? '—'} → ${ciclo.fecha_fin ?? '—'}` },
                        { etiqueta: 'Situación', valor: ciclo.situacion },
                        { etiqueta: 'Grupos', valor: ciclo.grupos_count },
                    ]"
                >
                    <template #insignia>
                        <span
                            class="shrink-0 rounded-full px-2 py-1 text-xs"
                            :style="ciclo.inscripcion_abierta
                                ? { backgroundColor: 'color-mix(in srgb, #16a34a 16%, transparent)' }
                                : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                        >
                            {{ ciclo.inscripcion_abierta ? 'Abierta' : 'Cerrada' }}
                        </span>
                    </template>
                    <template #acciones>
                        <BotonAccion variante="ver" texto="Captura" :href="`/escolar/ciclos/${ciclo.id}/ventanas`" />
                        <BotonAccion v-if="puedeEditar" variante="editar" solo-icono :href="`/escolar/ciclos/${ciclo.id}/edit`" />
                        <BotonAccion v-if="puedeEditar" variante="eliminar" solo-icono @click="eliminar(ciclo.id, ciclo.clave)" />
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ mensajeVacio }}
            </p>

            <section v-if="ciclos.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="ciclos.links" :total="ciclos.total" :desde="ciclos.from" :hasta="ciclos.to" />
            </section>
        </template>

        <!-- Lista -->
        <div v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="!vacio" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Ciclo</th>
                            <th class="px-4 py-3 font-semibold">Campus</th>
                            <th class="px-4 py-3 font-semibold">Periodo</th>
                            <th class="px-4 py-3 font-semibold text-center">Grupos</th>
                            <th class="px-4 py-3 font-semibold">Inscripción</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ciclo in ciclos.data" :key="ciclo.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <!-- Ciclo: nombre + clave + situación -->
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ ciclo.nombre }}</span>
                                <span class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ ciclo.clave }}<template v-if="ciclo.situacion"> · {{ ciclo.situacion }}</template>
                                </span>
                            </td>
                            <td class="px-4 py-4">
                                <span v-if="ciclo.campus.length === 0" class="text-xs" :style="{ color: 'var(--color-suave)' }">Todos (global)</span>
                                <span v-else class="flex flex-wrap gap-1">
                                    <span
                                        v-for="nombre in ciclo.campus"
                                        :key="nombre"
                                        class="rounded-full px-2 py-0.5 text-[11px]"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                    >{{ nombre }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-4 text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                {{ ciclo.fecha_inicio }} → {{ ciclo.fecha_fin }}
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ ciclo.grupos_count }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="ciclo.inscripcion_abierta ? 'Abierta' : 'Cerrada'" :color="ciclo.inscripcion_abierta ? '#16a34a' : 'var(--color-suave)'" />
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end gap-1">
                                    <BotonAccion variante="ver" texto="Captura" :href="`/escolar/ciclos/${ciclo.id}/ventanas`" />
                                    <BotonAccion v-if="puedeEditar" variante="editar" solo-icono :href="`/escolar/ciclos/${ciclo.id}/edit`" />
                                    <BotonAccion v-if="puedeEditar" variante="eliminar" solo-icono @click="eliminar(ciclo.id, ciclo.clave)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ mensajeVacio }}
                </p>
            </div>

            <Paginacion :enlaces="ciclos.links" :total="ciclos.total" :desde="ciclos.from" :hasta="ciclos.to" />
        </div>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
