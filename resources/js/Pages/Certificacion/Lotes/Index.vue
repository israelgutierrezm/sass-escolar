<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Lote {
    id: number;
    folio: string;
    nombre: string | null;
    tipo: string;
    tipo_label: string;
    estado: string;
    estado_label: string;
    estado_color: string;
    total: number;
    certificados: number;
    responsable: string | null;
    cerrado_en: string | null;
    firmado_en: string | null;
    creado_en: string | null;
}

defineProps<{ lotes: Lote[] }>();

const creando = ref(false);
const form = useForm({ nombre: '', tipo: 'total' });

function crear(): void {
    form.post('/certificacion/lotes', {
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
    });
}

// Eliminar desde el listado (como en Ciclos): la misma acción, en el mismo
// lugar. Un lote firmado no se elimina (ya emitió certificados).
function eliminar(lote: Lote): void {
    if (!confirm(`¿Eliminar el lote ${lote.folio}? Esta acción no se puede deshacer.`)) return;
    router.delete(`/certificacion/lotes/${lote.id}`);
}

// Tinte del color (color-mix sobre transparente): mismo criterio que las
// insignias del resto del sistema, funciona en claro y oscuro.
const estilosBadge: Record<string, { backgroundColor: string; color: string }> = {
    gris: { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' },
    ambar: { backgroundColor: 'color-mix(in srgb, #d97706 18%, transparent)', color: '#b45309' },
    verde: { backgroundColor: 'color-mix(in srgb, #16a34a 18%, transparent)', color: '#15803d' },
};

// Color SÓLIDO por color de estado del lote (para PildoraEstado).
const colorEstadoSolido: Record<string, string> = {
    gris: 'var(--color-suave)',
    ambar: '#d97706',
    verde: '#16a34a',
};
</script>

<template>
    <Head title="Lotes de certificación" />

    <AppLayout titulo="Lotes de certificación">
        <div class="mb-6 flex items-start justify-between gap-4">
            <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Agrupa alumnos que ya cerraron su plan para certificarlos juntos. Arma el lote, agrégale
                alumnos, ciérralo y fírmalo con la e.firma del responsable: cada alumno recibe su XML sellado.
            </p>
            <BotonAccion v-if="!creando" variante="nuevo" texto="Nuevo lote" class="shrink-0" @click="creando = true" />
        </div>

        <form v-if="creando" class="tarjeta mb-6 p-5" @submit.prevent="crear">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <CampoTexto
                        v-model="form.nombre"
                        etiqueta="Nombre del lote (opcional)"
                        placeholder="Ej. Egresados enero 2026"
                        :error="form.errors.nombre"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Tipo de certificado</label>
                    <div class="flex rounded-lg border p-0.5" :style="{ borderColor: 'var(--color-borde)' }">
                        <button
                            v-for="op in [{ v: 'total', t: 'Total' }, { v: 'parcial', t: 'Parcial' }]"
                            :key="op.v"
                            type="button"
                            class="rounded-md px-4 py-1.5 text-sm font-medium transition"
                            :style="form.tipo === op.v
                                ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                                : { color: 'var(--color-suave)' }"
                            @click="form.tipo = op.v"
                        >
                            {{ op.t }}
                        </button>
                    </div>
                </div>
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
                    <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                        <th class="px-6 py-3 font-semibold">Lote</th>
                        <th class="px-4 py-3 font-semibold">Estado</th>
                        <th class="px-4 py-3 text-center font-semibold">Alumnos</th>
                        <th class="px-4 py-3 font-semibold">Responsable</th>
                        <th class="px-4 py-3 font-semibold">Creado</th>
                        <th class="px-6 py-3 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="lote in lotes"
                        :key="lote.id"
                        class="fila-nueva border-t transition-colors"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-4">
                            <span class="flex items-center gap-2">
                                <span class="font-mono font-semibold text-contenido">{{ lote.folio }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }">{{ lote.tipo_label }}</span>
                            </span>
                            <span v-if="lote.nombre" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ lote.nombre }}</span>
                        </td>
                        <td class="px-4 py-4">
                            <PildoraEstado :texto="lote.estado_label" :color="colorEstadoSolido[lote.estado_color] ?? 'var(--color-suave)'" sin-capitalizar />
                        </td>
                        <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="lote.certificados > 0">{{ lote.certificados }}/{{ lote.total }}</span>
                            <span v-else>{{ lote.total }}</span>
                        </td>
                        <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ lote.responsable ?? '—' }}</td>
                        <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ lote.creado_en }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <BotonAccion variante="ver" texto="Abrir" :href="`/certificacion/lotes/${lote.id}`" />
                                <BotonAccion v-if="lote.estado !== 'firmado'" variante="eliminar" solo-icono @click="eliminar(lote)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="lotes.length === 0">
                        <td colspan="6" class="px-6 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                            Aún no hay lotes. Crea el primero para empezar a certificar.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
