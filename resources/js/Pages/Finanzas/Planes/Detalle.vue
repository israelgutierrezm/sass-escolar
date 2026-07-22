<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Regla {
    id: number;
    concepto: string | null;
    concepto_id: number;
    periodicidad: string;
    monto_base: number;
    dia_generacion: number | null;
    dia_limite: number | null;
    obligatorio: boolean;
    num_parcialidades: number | null;
    prorratea: boolean;
    prerequisito: string | null;
    concepto_prerequisito_id: number | null;
    adeudos: number;
}

const props = defineProps<{
    plan: {
        id: number;
        nombre: string;
        moneda: string;
        aplica_a_tipo: string;
        aplica_a_id: number | null;
        destinatario: string;
        vigente_desde: string | null;
        vigente_hasta: string | null;
        vigente: boolean;
    };
    reglas: Regla[];
    conceptos: { id: number; clave: string; nombre: string }[];
    periodicidades: { valor: string; etiqueta: string }[];
    destinos: { carrera: { id: number; nombre: string }[]; plan: { id: number; nombre: string }[]; oferta: { id: number; nombre: string }[] };
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const agregando = ref(false);
const editando = ref<number | null>(null);

const nueva = useForm({
    concepto_id: props.conceptos[0]?.id ?? null,
    periodicidad: 'mensual',
    monto_base: '' as string | number,
    dia_generacion: null as number | null,
    dia_limite: null as number | null,
    obligatorio: true,
    num_parcialidades: null as number | null,
    prorratea: false,
    concepto_prerequisito_id: null as number | null,
});

const vigencia = useForm({
    nombre: props.plan.nombre,
    moneda: props.plan.moneda,
    aplica_a_tipo: props.plan.aplica_a_tipo,
    aplica_a_id: props.plan.aplica_a_id,
    vigente_desde: props.plan.vigente_desde ?? '',
    vigente_hasta: props.plan.vigente_hasta ?? '',
});

// Los días solo tienen sentido en las periodicidades de calendario. En "por
// ciclo" y "por materia" el cargo nace con el ciclo, así que ofrecer un "día
// del mes" ahí solo produce configuraciones que el motor ignora.
const usaDiasDelMes = computed(() =>
    ['mensual', 'quincenal', 'unico'].includes(nueva.periodicidad),
);
const usaDiasDeSemana = computed(() => nueva.periodicidad === 'semanal');
const admiteParcialidades = computed(() => nueva.periodicidad === 'unico');

function agregar(): void {
    nueva.post(`/finanzas/planes/${props.plan.id}/reglas`, {
        preserveScroll: true,
        onSuccess: () => {
            nueva.reset('monto_base', 'dia_generacion', 'dia_limite', 'num_parcialidades', 'concepto_prerequisito_id');
            agregando.value = false;
        },
    });
}

function guardarVigencia(): void {
    vigencia.put(`/finanzas/planes/${props.plan.id}`, { preserveScroll: true });
}

function eliminarRegla(regla: Regla): void {
    router.delete(`/finanzas/planes/${props.plan.id}/reglas/${regla.id}`, { preserveScroll: true });
}

function eliminarPlan(): void {
    router.delete(`/finanzas/planes/${props.plan.id}`);
}

const etiquetaPeriodicidad = computed(() =>
    Object.fromEntries(props.periodicidades.map((p) => [p.valor, p.etiqueta])),
);
</script>

<template>
    <Head :title="`Plan de cobro · ${plan.nombre}`" />

    <AppLayout :titulo="plan.nombre">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Aplica a <strong>{{ plan.destinatario }}</strong> ·
                    {{ plan.vigente_desde }} → {{ plan.vigente_hasta ?? 'sin fin' }}
                    <span v-if="!plan.vigente" class="text-amber-700"> (fuera de vigencia: hoy no genera nada)</span>
                </p>
                <a href="/finanzas/planes" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Planes</a>
            </div>

            <form class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardarVigencia">
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Nombre</span>
                    <input
                        v-model="vigencia.nombre"
                        type="text"
                        required
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Vigente desde</span>
                    <input
                        v-model="vigencia.vigente_desde"
                        type="date"
                        required
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Vigente hasta</span>
                    <input
                        v-model="vigencia.vigente_hasta"
                        type="date"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>

                <div class="flex gap-2 sm:col-span-4">
                    <button
                        type="submit"
                        :disabled="vigencia.processing"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        Guardar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm text-red-600"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="eliminarPlan"
                    >
                        Eliminar plan
                    </button>
                </div>
            </form>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold">Reglas de cobro</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Cada regla emite un cargo por periodo. Repetir la generación no duplica nada.
                    </p>
                </div>
                <button
                    v-if="!agregando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="agregando = true"
                >
                    Agregar regla
                </button>
            </header>

            <form v-if="agregando" class="border-t px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="agregar">
                <div class="grid gap-4 sm:grid-cols-4">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Concepto</span>
                        <select
                            v-model="nueva.concepto_id"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option v-for="c in conceptos" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Periodicidad</span>
                        <select
                            v-model="nueva.periodicidad"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option v-for="p in periodicidades" :key="p.valor" :value="p.valor">{{ p.etiqueta }}</option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Monto</span>
                        <input
                            v-model="nueva.monto_base"
                            type="number"
                            step="0.01"
                            min="0"
                            required
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Requiere pagado antes</span>
                        <select
                            v-model="nueva.concepto_prerequisito_id"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <option :value="null">Nada</option>
                            <option v-for="c in conceptos" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                        </select>
                    </label>

                    <template v-if="usaDiasDelMes || usaDiasDeSemana">
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">
                                {{ usaDiasDeSemana ? 'Día de la semana en que se emite' : 'Día del mes en que se emite' }}
                            </span>
                            <input
                                v-model.number="nueva.dia_generacion"
                                type="number"
                                :min="1"
                                :max="usaDiasDeSemana ? 7 : 31"
                                class="w-full rounded-lg border px-3 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Día límite de pago</span>
                            <input
                                v-model.number="nueva.dia_limite"
                                type="number"
                                :min="1"
                                :max="usaDiasDeSemana ? 7 : 31"
                                class="w-full rounded-lg border px-3 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            />
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                Si cae antes del día de emisión, vence en el periodo siguiente.
                            </span>
                        </label>
                    </template>

                    <label v-if="admiteParcialidades" class="text-sm">
                        <span class="mb-1 block font-medium">Parcialidades</span>
                        <input
                            v-model.number="nueva.num_parcialidades"
                            type="number"
                            min="2"
                            max="36"
                            class="w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Parte el monto en N mensualidades.</span>
                    </label>

                    <label class="flex items-end gap-2 text-sm">
                        <input v-model="nueva.prorratea" type="checkbox" class="mb-2.5 rounded" />
                        <span class="mb-2 font-medium">Prorratear al ingresar a media periodicidad</span>
                    </label>
                </div>

                <div class="mt-4 flex gap-2">
                    <button
                        type="submit"
                        :disabled="nueva.processing"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        Agregar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="agregando = false"
                    >
                        Cancelar
                    </button>
                </div>
            </form>

            <table v-if="reglas.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Concepto</th>
                        <th class="px-4 py-3 font-medium">Periodicidad</th>
                        <th class="px-4 py-3 text-right font-medium">Monto</th>
                        <th class="px-4 py-3 font-medium">Emite / vence</th>
                        <th class="px-4 py-3 font-medium">Condiciones</th>
                        <th class="px-4 py-3 font-medium">Emitidos</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="regla in reglas" :key="regla.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3 font-medium">{{ regla.concepto ?? '—' }}</td>
                        <td class="px-4 py-3">{{ etiquetaPeriodicidad[regla.periodicidad] ?? regla.periodicidad }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(regla.monto_base) }}</td>
                        <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                            {{ regla.dia_generacion ?? '—' }} / {{ regla.dia_limite ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="regla.prerequisito" class="block">Requiere: {{ regla.prerequisito }}</span>
                            <span v-if="regla.num_parcialidades" class="block">{{ regla.num_parcialidades }} parcialidades</span>
                            <span v-if="regla.prorratea" class="block">Prorratea</span>
                            <span v-if="!regla.obligatorio" class="block">Opcional</span>
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ regla.adeudos }}</td>
                        <td class="px-6 py-3 text-right">
                            <button
                                v-if="regla.adeudos === 0"
                                type="button"
                                class="text-xs font-medium text-red-600"
                                @click="eliminarRegla(regla)"
                            >
                                Eliminar
                            </button>
                            <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                En uso
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Este plan no cobra nada todavía: agrégale al menos una regla.
            </p>
        </section>
    </AppLayout>
</template>
