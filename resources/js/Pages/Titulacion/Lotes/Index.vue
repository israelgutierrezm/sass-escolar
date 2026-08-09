<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import BarraListado from '@/Components/BarraListado.vue';
import SaldoDeEmision from '@/Components/SaldoDeEmision.vue';

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
    /** Cuántos rechazó la SEP: es el trabajo que queda por rehacer. */
    rechazados_ws: number;
    responsable: string | null;
    etapa_coincide: boolean;
    creado_en: string | null;
}

const props = defineProps<{
    lotes: Lote[];
    etapaActiva: string;
    /** `real`, `fake` o `off`: si lo que se envía sale de verdad hacia la SEP. */
    modoWs: string;
    filtros: { busqueda: string; estado: string; etapa: string; rechazados: string };
    saldo: {
        modalidad: string;
        etiqueta: string;
        creditos: number;
        cuenta_creditos: boolean;
        explicacion: string;
    };
}>();

const FILTROS = [
    {
        clave: 'estado',
        etiqueta: 'Estado',
        opciones: [
            { valor: 'borrador', texto: 'Borrador' },
            { valor: 'en_espera_firma', texto: 'En espera de firma' },
            { valor: 'firmado', texto: 'Firmado y sellado' },
            { valor: 'enviado', texto: 'Enviado a la SEP' },
        ],
    },
    {
        clave: 'etapa',
        etiqueta: 'Etapa',
        opciones: [
            { valor: 'pruebas', texto: 'Pruebas' },
            { valor: 'produccion', texto: 'Producción' },
        ],
    },
    // El que de verdad se usa tras enviar un lote grande: dónde quedó trabajo.
    { clave: 'rechazados', etiqueta: 'Con rechazos de la SEP', tipo: 'booleano' as const },
];

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

// Color SÓLIDO por color de estado del lote (para PildoraEstado).
const colorEstadoSolido: Record<string, string> = {
    gris: 'var(--color-suave)',
    ambar: '#d97706',
    azul: '#2563eb',
    verde: '#16a34a',
};
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
            <!--
                La etapa dice a QUÉ endpoint apunta el lote; el modo, si de verdad
                sale algo. Anunciar «producción» sin decir que el envío está
                simulado hace creer que los títulos ya llegaron a la SEP.
            -->
            <p
                v-if="modoWs !== 'real'"
                class="max-w-2xl rounded-lg border-l-4 border-l-amber-500 p-3 text-sm"
                style="background-color: color-mix(in srgb, #f59e0b 8%, transparent)"
            >
                <template v-if="modoWs === 'fake'">
                    El web service está en <strong>modo simulado</strong>: se puede armar y firmar el
                    lote, pero el envío no llega a la SEP aunque la etapa diga «{{ etapaActiva }}».
                </template>
                <template v-else>
                    El envío al web service está <strong>deshabilitado</strong>. Los lotes se pueden
                    armar y firmar, pero no se mandan.
                </template>
            </p>
            <BotonAccion v-if="!creando" variante="nuevo" texto="Nuevo lote" class="shrink-0" @click="creando = true" />
        </div>

        <SaldoDeEmision :saldo="saldo" class="mb-4" />

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

        <BarraListado
            url="/titulacion/lotes"
            :valores="filtros"
            :filtros="FILTROS"
            placeholder="Buscar por folio o nombre…"
            class="mb-4"
        />

        <div class="tarjeta overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                        <th class="px-6 py-3 font-semibold">Lote</th>
                        <th class="px-4 py-3 font-semibold">Etapa</th>
                        <th class="px-4 py-3 font-semibold">Estado</th>
                        <th class="px-4 py-3 text-center font-semibold">Egresados</th>
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
                            <span class="block font-mono font-semibold text-contenido">{{ lote.folio }}</span>
                            <span v-if="lote.nombre" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ lote.nombre }}</span>
                        </td>
                        <td class="px-4 py-4">
                            <span class="inline-flex items-center gap-1">
                                <PildoraEstado :texto="lote.etapa" :color="lote.etapa === 'produccion' ? '#16a34a' : '#d97706'" />
                                <span v-if="!lote.etapa_coincide" class="text-xs" :style="{ color: '#dc2626' }" title="La etapa del lote no coincide con la activa">⚠</span>
                            </span>
                        </td>
                        <td class="px-4 py-4">
                            <PildoraEstado :texto="lote.estado_label" :color="colorEstadoSolido[lote.estado_color] ?? 'var(--color-suave)'" sin-capitalizar />
                        </td>
                        <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="lote.titulados > 0">{{ lote.titulados }}/{{ lote.total }}</span>
                            <span v-else>{{ lote.total }}</span>
                            <!-- Lo rechazado se dice aquí para no abrir lote por lote. -->
                            <span
                                v-if="lote.rechazados_ws > 0"
                                class="mt-0.5 block text-[11px] font-medium text-red-600"
                                :title="`${lote.rechazados_ws} rechazado(s) por el web service`"
                            >
                                {{ lote.rechazados_ws }} rechazado{{ lote.rechazados_ws === 1 ? '' : 's' }}
                            </span>
                        </td>
                        <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ lote.responsable ?? '—' }}</td>
                        <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ lote.creado_en }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <BotonAccion variante="ver" texto="Abrir" :href="`/titulacion/lotes/${lote.id}`" />
                                <BotonAccion v-if="lote.estado !== 'firmado' && lote.estado !== 'enviado'" variante="eliminar" solo-icono @click="eliminar(lote)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="lotes.length === 0">
                        <td colspan="7" class="px-6 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                            Aún no hay lotes. Crea el primero para empezar a titular.
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
