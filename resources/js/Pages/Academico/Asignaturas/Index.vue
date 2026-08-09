<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaListado from '@/Components/TarjetaListado.vue';
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
    plan_clave: string | null;
}

// Texto del badge de planes. Catálogo puro: una asignatura puede vivir en
// varios planes. Cuando está en uno solo se muestra su clave (más útil que
// "1 plan"); en varios, el conteo; huérfana, "Sin plan".
function etiquetaPlanes(fila: Fila): string {
    if (fila.planes_count === 0) return 'Sin plan';
    if (fila.planes_count === 1) return fila.plan_clave ?? '1 plan';
    return `${fila.planes_count} planes`;
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

const vista = ref<'lista' | 'cuadricula'>('lista');

const ICONO_ASIGNATURA =
    'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25';

const definicionFiltros = computed(() => [
    { clave: 'tipo_asignatura_id', etiqueta: 'Tipo', opciones: props.tiposAsignatura.map((t) => ({ valor: t.id, texto: t.nombre })) },
    { clave: 'clasificacion_id', etiqueta: 'Clasificación', opciones: props.clasificaciones.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'area_id', etiqueta: 'Área', opciones: props.areas.map((a) => ({ valor: a.id, texto: a.nombre })) },
]);

const vacio = computed(() => !props.asignaturas.data.length);

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
            v-model:vista="vista"
            url="/academico/asignaturas"
            vista-clave="academico.asignaturas"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Buscar por clave o nombre…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nueva asignatura"
            nuevo-href="/academico/asignaturas/create"
            titulo="Asignaturas"
            descripcion="Catálogo de materias"
            :icono="ICONO_ASIGNATURA"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ asignaturas.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="!vacio" class="cuadricula-listado">
                <TarjetaListado
                    v-for="asignatura in asignaturas.data"
                    :key="asignatura.id"
                    :titulo="asignatura.nombre"
                    :clave="asignatura.clave"
                    :metas="[
                        { etiqueta: 'Tipo', valor: asignatura.tipo },
                        { etiqueta: 'Clasificación', valor: asignatura.clasificacion },
                        { etiqueta: 'Área', valor: asignatura.area },
                        { etiqueta: 'Créditos', valor: asignatura.creditos },
                        { etiqueta: 'Horas', valor: asignatura.horas || '—' },
                    ]"
                >
                    <template #insignia>
                        <span
                            class="shrink-0 rounded-full px-2 py-1 text-xs"
                            :class="{ 'font-mono': asignatura.planes_count === 1 && asignatura.plan_clave }"
                            :style="asignatura.planes_count
                                ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }
                                : { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                        >
                            {{ etiquetaPlanes(asignatura) }}
                        </span>
                    </template>
                    <template v-if="puedeEditar" #acciones>
                        <BotonAccion redondo variante="editar" solo-icono :href="`/academico/asignaturas/${asignatura.id}/edit`" />
                        <BotonAccion redondo variante="eliminar" solo-icono @click="eliminar(asignatura.id, asignatura.nombre)" />
                    </template>
                </TarjetaListado>
            </section>

            <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay asignaturas que coincidan con la búsqueda.
            </p>

            <section v-if="asignaturas.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="asignaturas.links" :total="asignaturas.total" :desde="asignaturas.from" :hasta="asignaturas.to" />
            </section>
        </template>

        <!-- Lista -->
        <div v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="!vacio" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Asignatura</th>
                            <th class="px-4 py-3 font-semibold">Tipo</th>
                            <th class="px-4 py-3 font-semibold">Clasificación</th>
                            <th class="px-4 py-3 font-semibold text-center">Créditos</th>
                            <th class="px-4 py-3 font-semibold text-center">Horas</th>
                            <th class="px-4 py-3 font-semibold">Planes</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="asignatura in asignaturas.data" :key="asignatura.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <!-- Asignatura: nombre + clave + área -->
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ asignatura.nombre }}</span>
                                <span class="mt-1 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    {{ asignatura.clave }}<template v-if="asignatura.area"> · {{ asignatura.area }}</template>
                                </span>
                            </td>
                            <td class="px-4 py-4">
                                <span v-if="asignatura.tipo" class="inline-block rounded-full px-2.5 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }">{{ asignatura.tipo }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ asignatura.clasificacion ?? '—' }}</td>
                            <td class="px-4 py-4 text-center tabular-nums">{{ asignatura.creditos }}</td>
                            <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ asignatura.horas || '—' }}</td>
                            <td class="px-4 py-4">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px]"
                                    :class="{ 'font-mono': asignatura.planes_count === 1 && asignatura.plan_clave }"
                                    :style="asignatura.planes_count
                                        ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }
                                        : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }"
                                >
                                    {{ etiquetaPlanes(asignatura) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
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
            </div>

            <Paginacion :enlaces="asignaturas.links" :total="asignaturas.total" :desde="asignaturas.from" :hasta="asignaturas.to" />
        </div>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
