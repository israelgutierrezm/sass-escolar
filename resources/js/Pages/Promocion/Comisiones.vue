<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Paginacion from '@/Components/Paginacion.vue';

interface Regla {
    id: number;
    nombre: string;
    aplica_a_tipo: string;
    destinatario: string;
    modo: string;
    valor: number;
    concepto: string | null;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    activo: boolean;
    devengadas: number;
}

const props = defineProps<{
    comisiones: {
        data: {
            id: number;
            promotor: string | null;
            matricula: string | null;
            aspirante_id: number | null;
            regla: string | null;
            monto: number;
            estatus: string;
            devengada_en: string | null;
            pagada_en: string | null;
            motivo_cancelacion: string | null;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: { estatus: string };
    totales: { estatus: string; total: number; monto: number }[];
    puedeGestionar: boolean;
    puedeConfigurar: boolean;
    reglas: Regla[];
    conceptos: { id: number; nombre: string }[];
    destinos: { carrera: { id: number; nombre: string }[]; oferta: { id: number; nombre: string }[] };
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const filtro = ref(props.filtros.estatus);
watch(filtro, () => {
    router.get('/promocion/comisiones', { estatus: filtro.value || undefined }, { preserveState: true, replace: true });
});

const seleccionadas = ref<number[]>([]);

function pagar(): void {
    router.post('/promocion/comisiones/pagar', { ids: seleccionadas.value }, {
        preserveScroll: true,
        onSuccess: () => (seleccionadas.value = []),
    });
}

const cancelando = ref<number | null>(null);
const cancelacion = useForm({ motivo: '' });

function cancelar(): void {
    if (!cancelando.value) return;

    cancelacion.post(`/promocion/comisiones/${cancelando.value}/cancelar`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelacion.reset();
            cancelando.value = null;
        },
    });
}

// Reglas.
const creandoRegla = ref(false);
const regla = useForm({
    nombre: '',
    aplica_a_tipo: 'global',
    aplica_a_id: null as number | null,
    modo: 'monto_fijo',
    valor: null as number | null,
    concepto_id: null as number | null,
    vigente_desde: new Date().toISOString().slice(0, 10),
    vigente_hasta: '',
});

watch(() => regla.aplica_a_tipo, () => (regla.aplica_a_id = null));

const opcionesDestino = computed<{ id: number; nombre: string }[]>(() => {
    const t = regla.aplica_a_tipo as keyof typeof props.destinos;
    return props.destinos[t] ?? [];
});

function guardarRegla(): void {
    regla.post('/promocion/reglas-comision', {
        preserveScroll: true,
        onSuccess: () => {
            regla.reset();
            creandoRegla.value = false;
        },
    });
}

const hayReglaVigente = computed(() => props.reglas.some((r) => r.activo && !r.vigente_hasta));

const colorEstatus: Record<string, string> = {
    devengada: 'text-amber-700 bg-amber-50',
    pagada: 'text-emerald-700 bg-emerald-50',
    cancelada: 'text-slate-600 bg-slate-100',
};
</script>

<template>
    <Head title="Comisiones" />

    <AppLayout titulo="Comisiones de promoción">
        <section class="tarjeta p-6">
            <p class="max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                El promotor devenga cuando su prospecto <strong>se inscribe</strong>, no cuando lo captura:
                se paga por resultado. El monto se congela al devengarse — si después cambias la regla, lo
                ya ganado no se recalcula, porque era el trato vigente cuando ese alumno entró.
            </p>

            <div v-if="totales.length" class="mt-4 grid gap-4 sm:grid-cols-3">
                <div v-for="t in totales" :key="t.estatus" class="rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">{{ t.estatus }}</p>
                    <p class="mt-1 text-xl font-semibold">{{ pesos.format(t.monto) }}</p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ t.total }} comisiones</p>
                </div>
            </div>
        </section>

        <section v-if="puedeConfigurar" class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Reglas de comisión</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Gana la más específica: oferta → carrera → toda la escuela.
                    </p>
                </div>
                <button
                    v-if="!creandoRegla"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="creandoRegla = true"
                >
                    Nueva regla
                </button>
            </div>

            <p v-if="!hayReglaVigente" class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                No hay ninguna regla vigente sin fecha de fin: mientras no exista una que aplique,
                <strong>nadie devengará comisiones</strong> al inscribirse un prospecto.
            </p>

            <form v-if="creandoRegla" class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardarRegla">
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Nombre</span>
                    <input v-model="regla.nombre" type="text" required placeholder="10% de la inscripción" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Aplica a</span>
                    <select v-model="regla.aplica_a_tipo" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="global">Toda la escuela</option>
                        <option value="carrera">Una carrera</option>
                        <option value="oferta">Una oferta</option>
                    </select>
                </label>
                <label v-if="regla.aplica_a_tipo !== 'global'" class="text-sm">
                    <span class="mb-1 block font-medium">¿Cuál?</span>
                    <select v-model="regla.aplica_a_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null" disabled>Elige…</option>
                        <option v-for="d in opcionesDestino" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Modo</span>
                    <select v-model="regla.modo" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="monto_fijo">Monto fijo</option>
                        <option value="porcentaje">Porcentaje</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">{{ regla.modo === 'porcentaje' ? 'Porcentaje' : 'Monto' }}</span>
                    <input v-model.number="regla.valor" type="number" step="0.01" min="0" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label v-if="regla.modo === 'porcentaje'" class="text-sm">
                    <span class="mb-1 block font-medium">Sobre qué concepto</span>
                    <select v-model="regla.concepto_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null" disabled>Elige…</option>
                        <option v-for="c in conceptos" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                    </select>
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Sin esto, «10%» no dice de qué.
                    </span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Vigente desde</span>
                    <input v-model="regla.vigente_desde" type="date" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Vigente hasta</span>
                    <input v-model="regla.vigente_hasta" type="date" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <div class="flex items-end gap-2 sm:col-span-4">
                    <button type="submit" :disabled="regla.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Crear regla
                    </button>
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creandoRegla = false">
                        Cancelar
                    </button>
                </div>
            </form>

            <ul v-if="reglas.length" class="mt-4 divide-y text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="r in reglas" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>
                        <span class="font-medium">{{ r.nombre }}</span>
                        <span class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ r.destinatario }} ·
                            {{ r.modo === 'porcentaje' ? `${r.valor}% de ${r.concepto ?? '—'}` : pesos.format(r.valor) }} ·
                            {{ r.vigente_desde }} → {{ r.vigente_hasta ?? 'sin fin' }}
                            <template v-if="r.devengadas"> · {{ r.devengadas }} devengadas</template>
                        </span>
                    </span>
                    <button
                        v-if="r.devengadas === 0"
                        type="button"
                        class="text-xs font-medium text-red-600"
                        @click="router.delete(`/promocion/reglas-comision/${r.id}`, { preserveScroll: true })"
                    >
                        Eliminar
                    </button>
                    <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">en uso</span>
                </li>
            </ul>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <select v-model="filtro" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                    <option value="">Todos los estatus</option>
                    <option value="devengada">Devengadas</option>
                    <option value="pagada">Pagadas</option>
                    <option value="cancelada">Canceladas</option>
                </select>

                <button
                    v-if="puedeGestionar && seleccionadas.length"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="pagar"
                >
                    Marcar {{ seleccionadas.length }} como pagadas
                </button>
            </header>

            <table v-if="comisiones.data.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th v-if="puedeGestionar" class="px-6 py-3"></th>
                        <th class="px-6 py-3 font-medium" :class="puedeGestionar ? 'pl-0' : ''">Promotor</th>
                        <th class="px-4 py-3 font-medium">Matrícula</th>
                        <th class="px-4 py-3 font-medium">Regla</th>
                        <th class="px-4 py-3 text-right font-medium">Monto</th>
                        <th class="px-4 py-3 font-medium">Devengada</th>
                        <th class="px-4 py-3 font-medium">Estatus</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in comisiones.data" :key="c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td v-if="puedeGestionar" class="px-6 py-3">
                            <input v-if="c.estatus === 'devengada'" v-model="seleccionadas" type="checkbox" :value="c.id" class="rounded" />
                        </td>
                        <td class="px-6 py-3 font-medium" :class="puedeGestionar ? 'pl-0' : ''">{{ c.promotor ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ c.matricula ?? '—' }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">{{ c.regla ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums">{{ pesos.format(c.monto) }}</td>
                        <td class="px-4 py-3 tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ c.devengada_en }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="colorEstatus[c.estatus] ?? ''">
                                {{ c.estatus }}
                            </span>
                            <span v-if="c.motivo_cancelacion" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ c.motivo_cancelacion }}
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <button
                                v-if="puedeGestionar && c.estatus === 'devengada'"
                                type="button"
                                class="text-xs font-medium text-red-600"
                                @click="cancelando = c.id"
                            >
                                Cancelar
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay comisiones. Se devengan solas cuando un prospecto con promotor titular se inscribe.
            </p>

            <Paginacion :enlaces="comisiones.links" :total="comisiones.total" :desde="comisiones.from" :hasta="comisiones.to" />
        </section>

        <section v-if="cancelando" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Cancelar comisión #{{ cancelando }}</h2>
            <form class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto]" @submit.prevent="cancelar">
                <input
                    v-model="cancelacion.motivo"
                    type="text"
                    required
                    minlength="10"
                    placeholder="Motivo — mínimo 10 caracteres"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                />
                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background-color: #dc2626">
                    Cancelar comisión
                </button>
                <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="cancelando = null">
                    Cerrar
                </button>
            </form>
        </section>
    </AppLayout>
</template>
