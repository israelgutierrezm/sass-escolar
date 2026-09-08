<script setup lang="ts">
/**
 * Órdenes de compra (compras). Se arma en borrador, se autoriza (compromiso) y
 * al recibirse genera la cuenta por pagar. La OC no crea egreso: el egreso nace
 * al pagar la CxP.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';

interface Concepto { id?: number; descripcion: string; cantidad: number; precio_unitario: number; importe?: number }
interface Orden {
    id: number; proveedor: string | null; centro: string | null; partida: string | null;
    fecha: string | null; estado: string; total: number; recibido: number; por_recibir: number;
    referencia: string | null; notas: string | null; autorizada_por: string | null; conceptos: Concepto[];
}
interface Opcion { valor: number; texto: string }

const props = defineProps<{
    ordenes: Orden[];
    permisos: { gestionar: boolean; autorizar: boolean };
    filtros: { estado: string; proveedor: number };
    estados: Record<string, string>;
    proveedores: Opcion[];
    centros: Opcion[];
    partidas: Opcion[];
    ciclos: Opcion[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
const hoy = new Date().toISOString().slice(0, 10);
const colorEstado: Record<string, string> = { borrador: '#6b7280', autorizada: '#a16207', recibida: '#c2410c', cerrada: '#0d9488', cancelada: '#6b7280' };

const filtros = reactive({ ...props.filtros });
function filtrar(): void {
    router.get('/finanzas/ordenes-compra', filtros, { preserveState: true, preserveScroll: true });
}

// Alta / edición.
const editando = ref<number | null>(null);
const abierto = ref(false);
const form = useForm({
    proveedor_id: props.proveedores[0]?.valor ?? 0,
    centro_costo_id: props.centros[0]?.valor ?? 0,
    partida_id: props.partidas[0]?.valor ?? 0,
    ciclo_id: props.ciclos[0]?.valor ?? 0,
    fecha: hoy, referencia: '', notas: '',
    conceptos: [{ descripcion: '', cantidad: 1, precio_unitario: 0 }] as Concepto[],
});

const totalForm = computed(() => form.conceptos.reduce((s, c) => s + (Number(c.cantidad) || 0) * (Number(c.precio_unitario) || 0), 0));

function nueva(): void {
    editando.value = null;
    form.reset();
    form.clearErrors();
    form.conceptos = [{ descripcion: '', cantidad: 1, precio_unitario: 0 }];
    abierto.value = true;
}

function editar(o: Orden): void {
    editando.value = o.id;
    form.clearErrors();
    form.fecha = o.fecha ?? hoy;
    form.referencia = o.referencia ?? '';
    form.notas = o.notas ?? '';
    form.conceptos = o.conceptos.map((c) => ({ descripcion: c.descripcion, cantidad: c.cantidad, precio_unitario: c.precio_unitario }));
    abierto.value = true;
}

function agregarConcepto(): void {
    form.conceptos.push({ descripcion: '', cantidad: 1, precio_unitario: 0 });
}
function quitarConcepto(i: number): void {
    if (form.conceptos.length > 1) form.conceptos.splice(i, 1);
}

function guardar(): void {
    const url = editando.value ? `/finanzas/ordenes-compra/${editando.value}` : '/finanzas/ordenes-compra';
    form.post(url, { preserveScroll: true, onSuccess: () => { abierto.value = false; } });
}

function autorizar(o: Orden): void {
    if (!confirm('¿Autorizar esta orden? Se vuelve un compromiso.')) return;
    router.post(`/finanzas/ordenes-compra/${o.id}/autorizar`, {}, { preserveScroll: true });
}
function cancelar(o: Orden): void {
    if (!confirm('¿Cancelar esta orden de compra?')) return;
    router.patch(`/finanzas/ordenes-compra/${o.id}/cancelar`, {}, { preserveScroll: true });
}

// Recepción.
const expandida = ref<number | null>(null);
const recepcion = reactive<Record<number, { monto: string; fecha: string; vencimiento: string; referencia: string }>>({});
function abrirRecepcion(o: Orden): void {
    expandida.value = expandida.value === o.id ? null : o.id;
    if (!recepcion[o.id]) recepcion[o.id] = { monto: String(o.por_recibir), fecha: hoy, vencimiento: hoy, referencia: '' };
}
function recibir(o: Orden): void {
    router.post(`/finanzas/ordenes-compra/${o.id}/recibir`, recepcion[o.id], { preserveScroll: true, onSuccess: () => { delete recepcion[o.id]; } });
}
</script>

<template>
    <Head title="Órdenes de compra" />

    <AppLayout titulo="Órdenes de compra">
        <div class="mx-auto max-w-5xl space-y-4">
            <!-- Filtros + alta -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="flex flex-wrap gap-3">
                        <label class="text-sm"><span class="mb-1 block font-medium">Estado</span>
                            <select v-model="filtros.estado" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @change="filtrar">
                                <option value="">Todos</option>
                                <option v-for="(etq, clave) in estados" :key="clave" :value="clave">{{ etq }}</option>
                            </select>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Proveedor</span>
                            <select v-model.number="filtros.proveedor" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @change="filtrar">
                                <option :value="0">Todos</option>
                                <option v-for="p in proveedores" :key="p.valor" :value="p.valor">{{ p.texto }}</option>
                            </select>
                        </label>
                    </div>
                    <button v-if="permisos.gestionar" type="button" class="rounded-lg px-3 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }" @click="nueva">Nueva orden</button>
                </div>

                <form v-if="abierto && permisos.gestionar" class="mt-4 space-y-3 rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="guardar">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm"><span class="mb-1 block font-medium">Proveedor *</span>
                            <select v-model.number="form.proveedor_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                <option v-for="p in proveedores" :key="p.valor" :value="p.valor">{{ p.texto }}</option>
                            </select>
                        </label>
                        <label class="text-sm"><span class="mb-1 block font-medium">Fecha *</span>
                            <input v-model="form.fecha" type="date" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
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
                        <label class="text-sm"><span class="mb-1 block font-medium">Referencia</span>
                            <input v-model="form.referencia" type="text" maxlength="100" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                    </div>

                    <!-- Conceptos -->
                    <div>
                        <p class="mb-1 text-sm font-medium">Conceptos</p>
                        <div v-for="(c, i) in form.conceptos" :key="i" class="mb-2 flex flex-wrap items-end gap-2">
                            <label class="min-w-0 flex-1 text-xs"><span class="mb-1 block" :style="{ color: 'var(--color-suave)' }">Descripción</span>
                                <input v-model="c.descripcion" type="text" required maxlength="255" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            </label>
                            <label class="w-20 text-xs"><span class="mb-1 block" :style="{ color: 'var(--color-suave)' }">Cant.</span>
                                <input v-model.number="c.cantidad" type="number" step="0.01" min="0.01" required class="w-full rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            </label>
                            <label class="w-28 text-xs"><span class="mb-1 block" :style="{ color: 'var(--color-suave)' }">Precio</span>
                                <input v-model.number="c.precio_unitario" type="number" step="0.01" min="0" required class="w-full rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                            </label>
                            <span class="w-24 py-2 text-right text-sm tabular-nums">{{ pesos.format((Number(c.cantidad) || 0) * (Number(c.precio_unitario) || 0)) }}</span>
                            <button type="button" class="py-2 text-xs" :style="{ color: '#b91c1c' }" @click="quitarConcepto(i)">✕</button>
                        </div>
                        <button type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="agregarConcepto">+ Agregar concepto</button>
                        <p class="mt-2 text-right text-sm font-semibold">Total: {{ pesos.format(totalForm) }}</p>
                    </div>

                    <label class="block text-sm"><span class="mb-1 block font-medium">Notas</span>
                        <textarea v-model="form.notas" rows="2" maxlength="500" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <div class="flex gap-2">
                        <button type="submit" :disabled="form.processing" class="rounded-lg px-4 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }">{{ editando ? 'Guardar' : 'Crear' }}</button>
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
                                <th class="px-4 py-2 font-medium">Proveedor</th>
                                <th class="px-4 py-2 font-medium">Fecha</th>
                                <th class="px-4 py-2 text-right font-medium">Total</th>
                                <th class="px-4 py-2 text-right font-medium">Por recibir</th>
                                <th class="px-4 py-2 font-medium">Estado</th>
                                <th class="px-4 py-2 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="o in ordenes" :key="o.id">
                                <tr class="border-b align-top" :style="{ borderColor: 'var(--color-borde)' }">
                                    <td class="px-4 py-2">
                                        <span class="font-medium">{{ o.proveedor }}</span>
                                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">#{{ o.id }}<template v-if="o.centro"> · {{ o.centro }}</template></span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2">{{ o.fecha }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ pesos.format(o.total) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ pesos.format(o.por_recibir) }}</td>
                                    <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: `color-mix(in srgb, ${colorEstado[o.estado]} 14%, transparent)`, color: colorEstado[o.estado] }">{{ estados[o.estado] }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        <button type="button" class="text-xs underline" :style="{ color: 'var(--color-suave)' }" @click="expandida = expandida === o.id ? null : o.id">Ver</button>
                                        <button v-if="permisos.gestionar && o.estado === 'borrador'" type="button" class="ml-3 text-xs underline" :style="{ color: 'var(--color-suave)' }" @click="editar(o)">Editar</button>
                                        <button v-if="permisos.autorizar && o.estado === 'borrador'" type="button" class="ml-3 text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="autorizar(o)">Autorizar</button>
                                        <button v-if="permisos.gestionar && ['autorizada', 'recibida'].includes(o.estado) && o.por_recibir > 0" type="button" class="ml-3 text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="abrirRecepcion(o)">Recibir</button>
                                        <button v-if="permisos.gestionar && ['borrador', 'autorizada'].includes(o.estado) && o.recibido === 0" type="button" class="ml-3 text-xs underline" :style="{ color: '#b91c1c' }" @click="cancelar(o)">Cancelar</button>
                                    </td>
                                </tr>
                                <tr v-if="expandida === o.id" class="border-b" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 5%, transparent)' }">
                                    <td colspan="6" class="px-4 py-3">
                                        <form v-if="permisos.gestionar && ['autorizada', 'recibida'].includes(o.estado) && o.por_recibir > 0 && recepcion[o.id]" class="mb-3 flex flex-wrap items-end gap-2" @submit.prevent="recibir(o)">
                                            <label class="text-xs"><span class="mb-1 block font-medium">Recibir (monto)</span>
                                                <input v-model="recepcion[o.id].monto" type="number" step="0.01" min="0.01" :max="o.por_recibir" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                            </label>
                                            <label class="text-xs"><span class="mb-1 block font-medium">Fecha factura</span>
                                                <input v-model="recepcion[o.id].fecha" type="date" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                            </label>
                                            <label class="text-xs"><span class="mb-1 block font-medium">Vence</span>
                                                <input v-model="recepcion[o.id].vencimiento" type="date" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                                            </label>
                                            <label class="min-w-0 flex-1 text-xs"><span class="mb-1 block font-medium">Referencia</span>
                                                <input v-model="recepcion[o.id].referencia" type="text" maxlength="100" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Folio de la factura…" />
                                            </label>
                                            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }">Recibir → cuenta por pagar</button>
                                        </form>
                                        <ul class="space-y-0.5 text-xs">
                                            <li v-for="c in o.conceptos" :key="c.id" class="flex justify-between gap-2">
                                                <span>{{ c.descripcion }} <span :style="{ color: 'var(--color-suave)' }">· {{ c.cantidad }} × {{ pesos.format(c.precio_unitario) }}</span></span>
                                                <span class="tabular-nums">{{ pesos.format(c.importe ?? 0) }}</span>
                                            </li>
                                        </ul>
                                        <p v-if="o.autorizada_por" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">Autorizó: {{ o.autorizada_por }}<template v-if="o.recibido > 0"> · Recibido: {{ pesos.format(o.recibido) }}</template></p>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!ordenes.length">
                                <td colspan="6" class="px-4 py-6 text-center text-sm" :style="{ color: 'var(--color-suave)' }">Sin órdenes de compra.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
