<script setup lang="ts">
/**
 * Cuentas por pagar (compras). Lo que la escuela le debe a cada proveedor, con
 * su vencimiento. Pagar una cuenta asienta un EGRESO —ahí el dinero sale—; el
 * saldo y el estado se derivan de esos pagos.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';

interface Pago { id: number; fecha: string | null; monto: number; referencia: string | null }
interface Cuenta {
    id: number; proveedor: string | null; centro: string | null; partida: string | null;
    concepto: string; monto: number; pagado: number; saldo: number;
    fecha: string | null; vencimiento: string | null; estado: string; vencida: boolean;
    referencia: string | null; pagos: Pago[];
}
interface Opcion { valor: number; texto: string }

const props = defineProps<{
    cuentas: Cuenta[];
    antiguedad: { por_vencer: number; vencido_1_30: number; vencido_31_60: number; vencido_60_mas: number };
    permisos: { gestionar: boolean; pagar: boolean };
    filtros: { estado: string; proveedor: number };
    estados: Record<string, string>;
    proveedores: Opcion[];
    centros: Opcion[];
    partidas: Opcion[];
    ciclos: Opcion[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
const hoy = new Date().toISOString().slice(0, 10);

const totalDeuda = computed(() => props.antiguedad.por_vencer + props.antiguedad.vencido_1_30 + props.antiguedad.vencido_31_60 + props.antiguedad.vencido_60_mas);

const buckets = [
    { clave: 'por_vencer' as const, etiqueta: 'Por vencer', color: '#0d9488' },
    { clave: 'vencido_1_30' as const, etiqueta: 'Vencido 1–30 d', color: '#a16207' },
    { clave: 'vencido_31_60' as const, etiqueta: 'Vencido 31–60 d', color: '#c2410c' },
    { clave: 'vencido_60_mas' as const, etiqueta: 'Vencido +60 d', color: '#b91c1c' },
];

// Filtros.
const filtros = reactive({ ...props.filtros });
function filtrar(): void {
    router.get('/finanzas/cuentas-pagar', filtros, { preserveState: true, preserveScroll: true });
}

// Alta / edición de la obligación.
const editando = ref<number | null>(null);
const abierto = ref(false);
const form = useForm({
    proveedor_id: props.proveedores[0]?.valor ?? 0,
    centro_costo_id: props.centros[0]?.valor ?? 0,
    partida_id: props.partidas[0]?.valor ?? 0,
    ciclo_id: props.ciclos[0]?.valor ?? 0,
    concepto: '', monto: '', fecha: hoy, vencimiento: hoy, referencia: '',
});

function nueva(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}

function guardar(): void {
    const url = editando.value ? `/finanzas/cuentas-pagar/${editando.value}` : '/finanzas/cuentas-pagar';
    form.post(url, { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); } });
}

function editar(c: Cuenta): void {
    editando.value = c.id;
    form.clearErrors();
    form.concepto = c.concepto;
    form.monto = String(c.monto);
    form.fecha = c.fecha ?? hoy;
    form.vencimiento = c.vencimiento ?? hoy;
    form.referencia = c.referencia ?? '';
    abierto.value = true;
}

function cancelarCuenta(c: Cuenta): void {
    if (!confirm('¿Cancelar esta cuenta por pagar?')) return;
    router.patch(`/finanzas/cuentas-pagar/${c.id}/cancelar`, {}, { preserveScroll: true });
}

// Pago (por cuenta).
const expandida = ref<number | null>(null);
const pago = reactive<Record<number, { monto: string; fecha: string; referencia: string }>>({});

function abrirPago(c: Cuenta): void {
    expandida.value = expandida.value === c.id ? null : c.id;
    if (!pago[c.id]) pago[c.id] = { monto: String(c.saldo), fecha: hoy, referencia: '' };
}

function pagar(c: Cuenta): void {
    router.post(`/finanzas/cuentas-pagar/${c.id}/pagar`, pago[c.id], { preserveScroll: true, onSuccess: () => { delete pago[c.id]; } });
}

function revertir(p: Pago): void {
    if (!confirm(`¿Revertir el pago de ${pesos.format(p.monto)}?`)) return;
    router.delete(`/finanzas/cuentas-pagar/pagos/${p.id}`, { preserveScroll: true });
}

const colorEstado: Record<string, string> = { pendiente: '#a16207', parcial: '#c2410c', pagada: '#0d9488', cancelada: '#6b7280' };
</script>

<template>
    <Head title="Cuentas por pagar" />

    <AppLayout titulo="Cuentas por pagar">
        <div class="mx-auto max-w-5xl space-y-4">
            <!-- Antigüedad de saldos -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-base font-semibold">Se debe {{ pesos.format(totalDeuda) }}</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">Antigüedad de saldos (lo vencido, aparte)</p>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-4">
                    <div v-for="b in buckets" :key="b.clave" class="rounded-lg border p-3" :style="{ borderColor: 'var(--color-borde)' }">
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ b.etiqueta }}</p>
                        <p class="mt-0.5 text-lg font-semibold tabular-nums" :style="{ color: b.color }">{{ pesos.format(antiguedad[b.clave]) }}</p>
                    </div>
                </div>
            </section>

            <!-- Filtros + alta -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="flex flex-wrap gap-3">
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Estado</span>
                            <select v-model="filtros.estado" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @change="filtrar">
                                <option value="">Todos</option>
                                <option v-for="(etq, clave) in estados" :key="clave" :value="clave">{{ etq }}</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Proveedor</span>
                            <select v-model.number="filtros.proveedor" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @change="filtrar">
                                <option :value="0">Todos</option>
                                <option v-for="p in proveedores" :key="p.valor" :value="p.valor">{{ p.texto }}</option>
                            </select>
                        </label>
                    </div>
                    <button v-if="permisos.gestionar" type="button" class="rounded-lg px-3 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }" @click="nueva">Nueva cuenta</button>
                </div>

                <form v-if="abierto && permisos.gestionar" class="mt-4 space-y-3 rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardar">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm"><span class="mb-1 block font-medium">Proveedor *</span>
                            <select v-model.number="form.proveedor_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="p in proveedores" :key="p.valor" :value="p.valor">{{ p.texto }}</option>
                            </select>
                            <span v-if="form.errors.proveedor_id" class="mt-1 block text-xs text-red-600">{{ form.errors.proveedor_id }}</span>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Concepto *</span>
                            <input v-model="form.concepto" type="text" required maxlength="255" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            <span v-if="form.errors.concepto" class="mt-1 block text-xs text-red-600">{{ form.errors.concepto }}</span>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Centro de costo *</span>
                            <select v-model.number="form.centro_costo_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="c in centros" :key="c.valor" :value="c.valor">{{ c.texto }}</option>
                            </select>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Partida *</span>
                            <select v-model.number="form.partida_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="p in partidas" :key="p.valor" :value="p.valor">{{ p.texto }}</option>
                            </select>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Ciclo *</span>
                            <select v-model.number="form.ciclo_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="c in ciclos" :key="c.valor" :value="c.valor">{{ c.texto }}</option>
                            </select>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Monto *</span>
                            <input v-model="form.monto" type="number" step="0.01" min="0.01" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            <span v-if="form.errors.monto" class="mt-1 block text-xs text-red-600">{{ form.errors.monto }}</span>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Fecha *</span>
                            <input v-model="form.fecha" type="date" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Vence *</span>
                            <input v-model="form.vencimiento" type="date" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            <span v-if="form.errors.vencimiento" class="mt-1 block text-xs text-red-600">{{ form.errors.vencimiento }}</span>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Referencia</span>
                            <input v-model="form.referencia" type="text" maxlength="100" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Folio de la factura…" />
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" :disabled="form.processing" class="rounded-lg px-4 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }">{{ editando ? 'Guardar' : 'Registrar' }}</button>
                        <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="abierto = false">Cancelar</button>
                    </div>
                </form>
            </section>

            <!-- Lista -->
            <section class="tarjeta overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                                <th class="px-4 py-2 font-medium">Proveedor / concepto</th>
                                <th class="px-4 py-2 text-right font-medium">Monto</th>
                                <th class="px-4 py-2 text-right font-medium">Saldo</th>
                                <th class="px-4 py-2 font-medium">Vence</th>
                                <th class="px-4 py-2 font-medium">Estado</th>
                                <th class="px-4 py-2 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="c in cuentas" :key="c.id">
                                <tr class="border-b align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                    <td class="px-4 py-2">
                                        <span class="font-medium">{{ c.proveedor }}</span>
                                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.concepto }}<template v-if="c.centro"> · {{ c.centro }}</template></span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ pesos.format(c.monto) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-medium">{{ pesos.format(c.saldo) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        {{ c.vencimiento }}
                                        <span v-if="c.vencida" class="ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, #b91c1c 14%, transparent)', color: '#b91c1c' }">vencida</span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: `color-mix(in srgb, ${colorEstado[c.estado]} 14%, transparent)`, color: colorEstado[c.estado] }">{{ estados[c.estado] }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        <button v-if="permisos.pagar && ['pendiente', 'parcial'].includes(c.estado)" type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="abrirPago(c)">Pagar</button>
                                        <button v-if="c.pagos.length" type="button" class="ml-3 text-xs underline" :style="{ color: 'var(--color-suave)' }" @click="expandida = expandida === c.id ? null : c.id">Pagos ({{ c.pagos.length }})</button>
                                        <button v-if="permisos.gestionar && c.pagado === 0 && ['pendiente'].includes(c.estado)" type="button" class="ml-3 text-xs underline" :style="{ color: 'var(--color-suave)' }" @click="editar(c)">Editar</button>
                                        <button v-if="permisos.gestionar && c.pagado === 0 && ['pendiente', 'parcial'].includes(c.estado)" type="button" class="ml-3 text-xs underline" :style="{ color: 'var(--color-suave)' }" @click="cancelarCuenta(c)">Cancelar</button>
                                    </td>
                                </tr>
                                <tr v-if="expandida === c.id" class="border-b" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 5%, transparent)' }">
                                    <td colspan="6" class="px-4 py-3">
                                        <form v-if="permisos.pagar && ['pendiente', 'parcial'].includes(c.estado) && pago[c.id]" class="mb-3 flex flex-wrap items-end gap-2" @submit.prevent="pagar(c)">
                                            <label class="text-sm"><span class="mb-1 block font-medium">Monto</span>
                                                <input v-model="pago[c.id].monto" type="number" step="0.01" min="0.01" :max="c.saldo" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                            </label>
                                            <label class="text-sm"><span class="mb-1 block font-medium">Fecha</span>
                                                <input v-model="pago[c.id].fecha" type="date" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                            </label>
                                            <label class="min-w-0 flex-1 text-sm"><span class="mb-1 block font-medium">Referencia</span>
                                                <input v-model="pago[c.id].referencia" type="text" maxlength="100" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Transferencia, cheque…" />
                                            </label>
                                            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }">Registrar pago</button>
                                        </form>
                                        <p v-if="!c.pagos.length" class="text-xs" :style="{ color: 'var(--color-suave)' }">Sin pagos todavía.</p>
                                        <ul v-else class="space-y-1">
                                            <li v-for="p in c.pagos" :key="p.id" class="flex flex-wrap items-center gap-2 text-xs">
                                                <span class="tabular-nums font-medium">{{ pesos.format(p.monto) }}</span>
                                                <span :style="{ color: 'var(--color-suave)' }">· {{ p.fecha }}<template v-if="p.referencia"> · {{ p.referencia }}</template></span>
                                                <button v-if="permisos.pagar" type="button" class="underline" :style="{ color: '#b91c1c' }" @click="revertir(p)">revertir</button>
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!cuentas.length">
                                <td colspan="6" class="px-4 py-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">Sin cuentas por pagar.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
