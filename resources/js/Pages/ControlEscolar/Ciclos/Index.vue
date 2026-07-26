<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
import Paginacion from '@/Components/Paginacion.vue';

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
        />

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
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-4 py-3 font-medium">Clave</th>
                            <th class="px-4 py-3 font-medium">Nombre</th>
                            <th class="px-4 py-3 font-medium">Campus</th>
                            <th class="px-4 py-3 font-medium">Periodo</th>
                            <th class="px-4 py-3 font-medium">Situación</th>
                            <th class="px-4 py-3 font-medium">Inscripción</th>
                            <th class="px-4 py-3 font-medium">Grupos</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ciclo in ciclos.data" :key="ciclo.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ ciclo.clave }}</td>
                            <td class="px-4 py-3 font-medium">{{ ciclo.nombre }}</td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                                <span v-if="ciclo.campus.length === 0">Todos (global)</span>
                                <span v-else class="flex flex-wrap gap-1">
                                    <span
                                        v-for="nombre in ciclo.campus"
                                        :key="nombre"
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :style="{ backgroundColor: 'var(--color-borde)' }"
                                    >
                                        {{ nombre }}
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ ciclo.fecha_inicio }} → {{ ciclo.fecha_fin }}
                            </td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ ciclo.situacion }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-1 text-xs"
                                    :style="ciclo.inscripcion_abierta
                                        ? { backgroundColor: 'color-mix(in srgb, #16a34a 16%, transparent)' }
                                        : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                                >
                                    {{ ciclo.inscripcion_abierta ? 'Abierta' : 'Cerrada' }}
                                </span>
                            </td>
                            <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ ciclo.grupos_count }}</td>
                            <td class="px-4 py-3">
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
