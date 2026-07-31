<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';

interface Lote {
    id: number;
    folio: string;
    nombre: string | null;
    etapa: string;
    estado: string;
    estado_label: string;
    estado_color: string;
    total: number;
    titulados: number;
    responsable: string | null;
    etapa_coincide: boolean;
    creado_en: string | null;
}

defineProps<{ lotes: Lote[]; etapaActiva: string }>();

const creando = ref(false);
const form = useForm({ nombre: '' });

function crear(): void {
    form.post('/titulacion/lotes', {
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
    });
}

function eliminar(lote: Lote): void {
    if (!confirm(`¿Eliminar el lote ${lote.folio}? Esta acción no se puede deshacer.`)) return;
    router.delete(`/titulacion/lotes/${lote.id}`);
}

const estilosBadge: Record<string, { backgroundColor: string; color: string }> = {
    gris: { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' },
    ambar: { backgroundColor: 'color-mix(in srgb, #d97706 18%, transparent)', color: '#b45309' },
    azul: { backgroundColor: 'color-mix(in srgb, #2563eb 18%, transparent)', color: '#1d4ed8' },
    verde: { backgroundColor: 'color-mix(in srgb, #16a34a 18%, transparent)', color: '#15803d' },
};

function estiloEtapa(etapa: string): { backgroundColor: string; color: string } {
    const c = etapa === 'produccion' ? '#16a34a' : '#d97706';
    return { backgroundColor: `color-mix(in srgb, ${c} 15%, transparent)`, color: c };
}
</script>

<template>
    <Head title="Lotes de titulación" />

    <AppLayout titulo="Lotes de titulación">
        <div class="mb-6 flex items-start justify-between gap-4">
            <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Agrupa egresados para titularlos juntos. Arma el lote, agrégale egresados, ciérralo y fírmalo
                con la e.firma del responsable; luego envía los títulos al web service de la SEP. Cada lote
                se crea en la etapa activa (<span class="font-medium">{{ etapaActiva }}</span>).
            </p>
            <BotonAccion v-if="!creando" variante="nuevo" texto="Nuevo lote" class="shrink-0" @click="creando = true" />
        </div>

        <form v-if="creando" class="tarjeta mb-6 p-5" @submit.prevent="crear">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <CampoTexto
                        v-model="form.nombre"
                        etiqueta="Nombre del lote (opcional)"
                        marcador="Ej. Titulados enero 2026"
                        :error="form.errors.nombre"
                    />
                </div>
                <span class="rounded-lg px-3 py-2 text-xs font-medium" :style="estiloEtapa(etapaActiva)">
                    Se creará en etapa: {{ etapaActiva }}
                </span>
                <BotonPrincipal :procesando="form.processing" texto="Crear lote" />
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="creando = false; form.reset()"
                >
                    Cancelar
                </button>
            </div>
        </form>

        <div class="tarjeta overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <th class="px-5 py-3 font-medium">Folio</th>
                        <th class="px-5 py-3 font-medium">Nombre</th>
                        <th class="px-5 py-3 font-medium">Etapa</th>
                        <th class="px-5 py-3 font-medium">Estado</th>
                        <th class="px-5 py-3 text-center font-medium">Egresados</th>
                        <th class="px-5 py-3 font-medium">Responsable</th>
                        <th class="px-5 py-3 font-medium">Creado</th>
                        <th class="px-5 py-3 text-right font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="lote in lotes"
                        :key="lote.id"
                        class="border-b transition hover:bg-black/[.02] dark:hover:bg-white/[.03]"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-5 py-3 font-mono font-medium">{{ lote.folio }}</td>
                        <td class="px-5 py-3">{{ lote.nombre ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium capitalize" :style="estiloEtapa(lote.etapa)">
                                {{ lote.etapa }}
                            </span>
                            <span v-if="!lote.etapa_coincide" class="ml-1 text-xs" :style="{ color: '#dc2626' }" title="La etapa del lote no coincide con la activa">⚠</span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="estilosBadge[lote.estado_color]">
                                {{ lote.estado_label }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span v-if="lote.titulados > 0" :style="{ color: 'var(--color-suave)' }">{{ lote.titulados }}/{{ lote.total }}</span>
                            <span v-else>{{ lote.total }}</span>
                        </td>
                        <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ lote.responsable ?? '—' }}</td>
                        <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ lote.creado_en }}</td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <BotonAccion variante="ver" texto="Abrir" :href="`/titulacion/lotes/${lote.id}`" />
                                <BotonAccion v-if="lote.estado !== 'firmado' && lote.estado !== 'enviado'" variante="eliminar" solo-icono @click="eliminar(lote)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="lotes.length === 0">
                        <td colspan="8" class="px-5 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                            Aún no hay lotes. Crea el primero para empezar a titular.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
