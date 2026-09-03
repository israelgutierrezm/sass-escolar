<script setup lang="ts">
/**
 * La bandeja de solicitudes.
 *
 * ── Arranca en lo que ESPERA ALGO, no en todo ──────────────────────────────
 * Sin filtro se enseña la bandeja —solicitado, en revisión y aprobado sin
 * asignar—. Un listado que abre con los seiscientos expedientes históricos
 * entierra los ocho que hay que atender hoy, y a la tercera vez nadie lo mira.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Expediente {
    id: number;
    estado: string;
    estado_texto: string;
    estado_color: string;
    alumno: string | null;
    matricula: string | null;
    programa: string | null;
    campus: string | null;
    tipo: string | null;
    organizacion: string | null;
    fecha_solicitud: string | null;
}

const props = defineProps<{
    expedientes: { data: Expediente[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filtros: Record<string, string | number | null>;
    estados: { valor: string; texto: string; color: string }[];
    catalogos: {
        tiposProceso: { id: number; nombre: string }[];
        campus: { id: number; nombre: string }[];
    };
    puedeRevisar: boolean;
}>();

const filtros = ref({
    estado: props.filtros.estado ?? '',
    tipo: props.filtros.tipo ?? '',
    campus: props.filtros.campus ?? '',
    buscar: props.filtros.buscar ?? '',
});

let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(filtros, (valor) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get('/procesos/expedientes', { ...valor }, { preserveState: true, replace: true });
    }, 300);
}, { deep: true });

const enBandeja = computed(() => filtros.value.estado === '');
</script>

<template>
    <Head title="Solicitudes de servicio social y prácticas" />

    <AppLayout titulo="Solicitudes">
        <section class="tarjeta mb-4 p-5">
            <h2 class="font-semibold">Lo que espera respuesta</h2>
            <p v-if="enBandeja" class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Se enseña lo que necesita que alguien haga algo: enviadas, en revisión y aprobadas
                sin organización. Elige un estado para ver el resto.
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <CampoTexto v-model="filtros.buscar" etiqueta="Buscar" marcador="Nombre o matrícula" />
                <CampoSelect
                    v-model="filtros.estado"
                    etiqueta="Estado"
                    :opciones="estados.map((e) => ({ valor: e.valor, texto: e.texto }))"
                    vacio="Sólo lo pendiente"
                />
                <CampoSelect
                    v-model="filtros.tipo"
                    etiqueta="Proceso"
                    :opciones="catalogos.tiposProceso.map((t) => ({ valor: t.id, texto: t.nombre }))"
                    vacio="Todos"
                />
                <CampoSelect
                    v-model="filtros.campus"
                    etiqueta="Campus"
                    :opciones="catalogos.campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    vacio="Todos"
                />
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="expedientes.data.length" class="w-full text-sm">
                    <thead>
                        <tr
                            class="text-left text-[11px] uppercase tracking-wider"
                            :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }"
                        >
                            <th class="px-6 py-3 font-semibold">Alumno</th>
                            <th class="px-4 py-3 font-semibold">Proceso</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-4 py-3 font-semibold">Organización</th>
                            <th class="px-4 py-3 font-semibold">Solicitó</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="e in expedientes.data"
                            :key="e.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-6 py-4">
                                <span class="font-semibold text-contenido">{{ e.alumno ?? '—' }}</span>
                                <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ e.matricula }} · {{ e.programa ?? '—' }}<span v-if="e.campus"> · {{ e.campus }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-4">{{ e.tipo ?? '—' }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="e.estado_texto" :color="e.estado_color" sin-capitalizar />
                            </td>
                            <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ e.organizacion ?? 'Sin asignar' }}
                            </td>
                            <td class="px-4 py-4 text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                {{ e.fecha_solicitud ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end">
                                    <BotonAccion variante="ver" texto="Abrir" :href="`/procesos/expedientes/${e.id}`" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    <template v-if="enBandeja">
                        No hay nada esperando respuesta. Elige un estado arriba para ver los demás expedientes.
                    </template>
                    <template v-else>
                        Ningún expediente con esos filtros.
                    </template>
                </p>
            </div>

            <Paginacion v-if="expedientes.data.length" :enlaces="expedientes.links" :total="expedientes.total" />
        </section>
    </AppLayout>
</template>
