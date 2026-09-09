<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Solicitud {
    id: number;
    alumno: string | null;
    matricula: string | null;
    programa_academico: string | null;
    receptor_rfc: string;
    receptor_razon_social: string;
    operaciones: number;
    estado: string;
    motivo_rechazo: string | null;
    factura: { id: number; uuid: string | null; estatus: string } | null;
    solicitante: string | null;
    solicitada_en: string | null;
}

const props = defineProps<{
    solicitudes: Solicitud[];
    estado: string;
    pendientes: number;
}>();

const filtros = [
    { valor: 'pendiente', texto: 'Pendientes' },
    { valor: 'emitida', texto: 'Emitidas' },
    { valor: 'rechazada', texto: 'Rechazadas' },
];

function filtrar(estado: string): void {
    router.get('/finanzas/solicitudes-factura', { estado }, { preserveScroll: true, preserveState: true });
}

const emitiendo = ref<number | null>(null);

function emitir(id: number): void {
    emitiendo.value = id;
    router.post(`/finanzas/solicitudes-factura/${id}/emitir`, {}, {
        preserveScroll: true,
        onFinish: () => { emitiendo.value = null; },
    });
}

// Rechazar pide motivo: se abre en línea.
const rechazandoId = ref<number | null>(null);
const motivo = ref('');

function abrirRechazo(id: number): void {
    rechazandoId.value = rechazandoId.value === id ? null : id;
    motivo.value = '';
}

function rechazar(id: number): void {
    router.post(`/finanzas/solicitudes-factura/${id}/rechazar`, { motivo: motivo.value }, {
        preserveScroll: true,
        onSuccess: () => { rechazandoId.value = null; motivo.value = ''; },
    });
}
</script>

<template>
    <Head title="Solicitudes de factura" />

    <AppLayout titulo="Solicitudes de factura">
        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold">Solicitudes de factura</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Lo que pide el alumno y su familia. Emitir es un acto fiscal: nace el CFDI con el motor de siempre.
                    </p>
                </div>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="f in filtros"
                        :key="f.valor"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm"
                        :style="props.estado === f.valor
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                            : { color: 'var(--color-suave)' }"
                        @click="filtrar(f.valor)"
                    >
                        {{ f.texto }}<span v-if="f.valor === 'pendiente' && pendientes"> ({{ pendientes }})</span>
                    </button>
                </div>
            </header>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-t text-left text-[11px] uppercase tracking-wider" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <th class="px-6 py-3 font-semibold">Alumno</th>
                            <th class="px-4 py-3 font-semibold">Receptor</th>
                            <th class="px-4 py-3 text-center font-semibold">Operaciones</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-6 py-3 text-right font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in solicitudes" :key="s.id" class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3">
                                <p class="font-medium">{{ s.alumno ?? '—' }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ s.matricula }} · {{ s.programa_academico }} · {{ s.solicitada_en }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-mono text-xs">{{ s.receptor_rfc }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ s.receptor_razon_social }}</p>
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums">{{ s.operaciones }}</td>
                            <td class="px-4 py-3">
                                <PildoraEstado :texto="s.estado" />
                                <p v-if="s.motivo_rechazo" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ s.motivo_rechazo }}</p>
                                <a
                                    v-if="s.factura?.uuid"
                                    :href="`/finanzas/facturas/${s.factura.id}`"
                                    class="mt-1 block text-xs font-medium"
                                    :style="{ color: 'var(--color-acento)' }"
                                >Ver factura</a>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <div v-if="s.estado === 'pendiente'" class="flex flex-col items-end gap-2">
                                    <div class="flex gap-3">
                                        <button
                                            type="button"
                                            class="text-xs font-medium"
                                            :style="{ color: 'var(--color-acento)' }"
                                            :disabled="emitiendo === s.id"
                                            @click="emitir(s.id)"
                                        >
                                            {{ emitiendo === s.id ? 'Emitiendo…' : 'Emitir' }}
                                        </button>
                                        <button type="button" class="text-xs font-medium text-red-600" @click="abrirRechazo(s.id)">
                                            Rechazar
                                        </button>
                                    </div>
                                    <div v-if="rechazandoId === s.id" class="w-64">
                                        <textarea
                                            v-model="motivo"
                                            rows="2"
                                            placeholder="¿Por qué se rechaza?"
                                            class="w-full rounded-lg border bg-transparent px-2 py-1 text-xs"
                                            :style="{ borderColor: 'var(--color-borde)' }"
                                        />
                                        <button
                                            type="button"
                                            class="mt-1 rounded-lg px-3 py-1 text-xs font-medium disabled:opacity-60"
                                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                            :disabled="motivo.trim().length < 5"
                                            @click="rechazar(s.id)"
                                        >
                                            Rechazar solicitud
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!solicitudes.length">
                            <td colspan="5" class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                                No hay solicitudes {{ props.estado === 'pendiente' ? 'pendientes' : 'en este estado' }}.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
