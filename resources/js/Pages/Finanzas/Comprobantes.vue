<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * La cola de comprobantes de transferencia por validar.
 *
 * ── Aprobar aquí es cobrar ─────────────────────────────────────────────────
 * Hasta que alguien pulsa «Aprobar», el cargo del alumno sigue abierto aunque
 * el dinero esté en la cuenta. Por eso lo pendiente va primero y se dice cuánto
 * lleva esperando: cada día de retraso es un alumno que cree haber pagado y ve
 * su adeudo vivo.
 */
interface Comprobante {
    id: number;
    alumno: string | null;
    matricula: string | null;
    programa_academico: string | null;
    cuenta: string | null;
    banco: string | null;
    monto: number;
    fecha: string | null;
    referencia: string | null;
    estado: string;
    motivo_rechazo: string | null;
    revisor: string | null;
    revisado_en: string | null;
    subido_en: string | null;
    cargos: number;
}

const props = defineProps<{
    comprobantes: Comprobante[];
    estado: string;
    pendientes: number;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const ESTADOS = [
    { valor: 'pendiente', texto: 'Por revisar' },
    { valor: 'aprobado', texto: 'Aprobados' },
    { valor: 'rechazado', texto: 'Rechazados' },
];

/** El que se está resolviendo, y con qué monto o motivo. */
const resolviendo = ref<Comprobante | null>(null);
const modo = ref<'aprobar' | 'rechazar'>('aprobar');

const form = useForm({ monto: '' as string | number, motivo: '' });

function abrir(c: Comprobante, accion: 'aprobar' | 'rechazar'): void {
    resolviendo.value = c;
    modo.value = accion;
    form.reset();
    // Se precarga lo declarado: casi siempre es correcto y teclearlo otra vez
    // es donde se equivoca uno.
    form.monto = c.monto;
}

function confirmar(): void {
    if (!resolviendo.value) return;

    const destino = `/finanzas/comprobantes/${resolviendo.value.id}/${modo.value}`;

    form.post(destino, {
        preserveScroll: true,
        onSuccess: () => { resolviendo.value = null; },
    });
}

function verArchivo(c: Comprobante): void {
    window.open(`/comprobantes/${c.id}/archivo`, '_blank');
}

function filtrar(estado: string): void {
    router.get('/finanzas/comprobantes', { estado }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Comprobantes de pago" />

    <AppLayout titulo="Comprobantes de pago">
        <p class="mb-4 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Transferencias que los alumnos dicen haber hecho. Hasta que se aprueban, su cargo sigue
            abierto: aprobar aquí es registrar el pago y liquidarlo.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button
                v-for="e in ESTADOS"
                :key="e.valor"
                type="button"
                class="rounded-lg border px-3 py-1.5 text-sm transition"
                :style="estado === e.valor
                    ? { borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }
                    : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                @click="filtrar(e.valor)"
            >
                {{ e.texto }}
                <span v-if="e.valor === 'pendiente' && pendientes" class="ml-1 font-semibold">({{ pendientes }})</span>
            </button>
        </div>

        <div class="tarjeta overflow-hidden">
            <div v-if="comprobantes.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                            <th class="px-4 py-3 font-semibold">Alumno</th>
                            <th class="px-4 py-3 font-semibold">Transferencia</th>
                            <th class="px-4 py-3 text-right font-semibold">Monto</th>
                            <th class="px-4 py-3 font-semibold">Estado</th>
                            <th class="px-4 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in comprobantes" :key="c.id" class="border-t align-top" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ c.alumno ?? '—' }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ c.matricula }}<template v-if="c.programa_academico"> · {{ c.programa_academico }}</template>
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <p>{{ c.fecha ?? '—' }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    <template v-if="c.cuenta">{{ c.cuenta }}</template>
                                    <template v-if="c.referencia"> · ref. {{ c.referencia }}</template>
                                    <template v-if="c.cargos"> · {{ c.cargos === 1 ? '1 cargo' : `${c.cargos} cargos` }}</template>
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums">{{ pesos.format(c.monto) }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :style="c.estado === 'aprobado'
                                        ? { backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)', color: '#15803d' }
                                        : c.estado === 'rechazado'
                                            ? { backgroundColor: 'color-mix(in srgb, #dc2626 14%, transparent)', color: '#b91c1c' }
                                            : { backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                                >{{ c.estado }}</span>
                                <p v-if="c.motivo_rechazo" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.motivo_rechazo }}</p>
                                <p v-if="c.revisor" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ c.revisor }} · {{ c.revisado_en }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="verArchivo(c)">
                                        Ver comprobante
                                    </button>
                                    <template v-if="c.estado === 'pendiente'">
                                        <button type="button" class="rounded-lg px-2.5 py-1 text-xs font-medium" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }" @click="abrir(c, 'aprobar')">
                                            Aprobar
                                        </button>
                                        <button type="button" class="rounded-lg border px-2.5 py-1 text-xs text-red-600" :style="{ borderColor: 'var(--color-borde)' }" @click="abrir(c, 'rechazar')">
                                            Rechazar
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay comprobantes {{ estado === 'pendiente' ? 'por revisar' : estado + 's' }}.
            </p>
        </div>

        <!-- Confirmación -->
        <div v-if="resolviendo" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="tarjeta w-full max-w-md p-6">
                <h2 class="font-semibold">
                    {{ modo === 'aprobar' ? 'Aprobar el comprobante' : 'Rechazar el comprobante' }}
                </h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ resolviendo.alumno }} · {{ pesos.format(resolviendo.monto) }}
                </p>

                <template v-if="modo === 'aprobar'">
                    <label class="mt-4 block text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">
                            Monto que de verdad entró
                        </span>
                        <input v-model="form.monto" type="number" step="0.01" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                        <!-- El banco manda sobre lo que declaró quien pagó. -->
                        <span class="mt-1 block text-xs" :style="{ color: 'var(--color-suave)' }">
                            Corrígelo si el comprobante dice otra cosa: se registra lo que entró.
                        </span>
                    </label>
                    <p class="mt-3 text-sm">
                        Al aprobar se registra el pago y se aplica a sus cargos.
                    </p>
                </template>

                <template v-else>
                    <label class="mt-4 block text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">
                            ¿Por qué se rechaza?
                        </span>
                        <textarea v-model="form.motivo" rows="3" placeholder="El comprobante es de otro monto…" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                        <span v-if="form.errors.motivo" class="text-xs text-red-600">{{ form.errors.motivo }}</span>
                        <span class="mt-1 block text-xs" :style="{ color: 'var(--color-suave)' }">
                            Lo verá quien lo subió: sin motivo tendría que adivinar qué corregir.
                        </span>
                    </label>
                </template>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        :disabled="form.processing"
                        @click="confirmar"
                    >
                        {{ form.processing ? 'Guardando…' : (modo === 'aprobar' ? 'Aprobar y registrar el pago' : 'Rechazar') }}
                    </button>
                    <button type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="resolviendo = null">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
