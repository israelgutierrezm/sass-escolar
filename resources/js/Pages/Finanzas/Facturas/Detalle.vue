<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Concepto {
    id: number;
    clave_sat: string;
    descripcion: string;
    cantidad: number;
    valor_unitario: number;
    importe: number;
    iva: number;
    pago_id: number | null;
    pago_metodo: string | null;
}

const props = defineProps<{
    factura: {
        id: number;
        uuid: string | null;
        estatus: string;
        emisor_rfc: string | null;
        emisor_razon_social: string | null;
        emisor_regimen_fiscal: string | null;
        emisor_cp: string | null;
        receptor_rfc: string;
        receptor_razon_social: string;
        receptor_uso_cfdi: string;
        receptor_regimen_fiscal: string;
        receptor_cp: string;
        forma_pago_sat: string | null;
        metodo_pago_sat: string;
        moneda: string;
        subtotal: number;
        iva: number;
        total: number;
        pac: string | null;
        intentos: number;
        ultimo_error: string | null;
        fecha_timbrado: string | null;
        cancelada_en: string | null;
        motivo_cancelacion: string | null;
        editable: boolean;
        fiscal: boolean;
        tiene_xml: boolean;
        tiene_pdf: boolean;
        matricula_id: number | null;
        matricula: string | null;
        alumno: string | null;
        sustituye: { id: number; uuid: string | null } | null;
        sustituida_por: { id: number; uuid: string | null }[];
    };
    conceptos: Concepto[];
    motivos: { valor: string; etiqueta: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const cancelando = ref(false);
const cancelacion = useForm({ motivo: '02', sustituta_id: null as number | null });

const refacturando = ref(false);
const refactura = useForm({
    rfc: props.factura.receptor_rfc,
    razon_social: props.factura.receptor_razon_social,
    uso_cfdi: props.factura.receptor_uso_cfdi,
    regimen_fiscal: props.factura.receptor_regimen_fiscal,
    cp: props.factura.receptor_cp,
});

function refacturar(): void {
    refactura.post(`/finanzas/facturas/${props.factura.id}/refacturar`);
}

function cancelar(): void {
    cancelacion.post(`/finanzas/facturas/${props.factura.id}/cancelar`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelando.value = false;
        },
    });
}

function reintentar(): void {
    router.post(`/finanzas/facturas/${props.factura.id}/reintentar`, {}, { preserveScroll: true });
}

function eliminar(): void {
    router.delete(`/finanzas/facturas/${props.factura.id}`);
}

const colorEstatus: Record<string, string> = {
    borrador: 'text-slate-600 bg-slate-100',
    timbrando: 'text-blue-700 bg-blue-50',
    timbrada: 'text-emerald-700 bg-emerald-50',
    error: 'text-red-700 bg-red-50',
    cancelada: 'text-violet-700 bg-violet-50',
};
</script>

<template>
    <Head :title="`Factura ${factura.uuid ?? factura.id}`" />

    <AppLayout titulo="Factura">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded px-2 py-0.5 text-xs font-medium" :class="colorEstatus[factura.estatus] ?? ''">
                            {{ factura.estatus }}
                        </span>
                        <span v-if="factura.uuid" class="font-mono text-sm">{{ factura.uuid }}</span>
                        <span v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">Sin folio fiscal todavía</span>
                    </div>
                    <p v-if="factura.alumno" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ factura.alumno }} · <span class="font-mono">{{ factura.matricula }}</span>
                    </p>
                </div>
                <a href="/finanzas/facturas" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Facturas</a>
            </div>

            <p v-if="factura.ultimo_error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                <strong>El PAC la rechazó</strong> (intento {{ factura.intentos }}): {{ factura.ultimo_error }}
            </p>

            <p v-if="factura.estatus === 'timbrando'" class="mt-4 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                En la cola, esperando al PAC. Recarga en un momento.
            </p>

            <div
                v-if="factura.cancelada_en"
                class="mt-4 rounded-lg border px-4 py-3 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                Cancelada el {{ factura.cancelada_en }} con motivo {{ factura.motivo_cancelacion }}.
                Sus pagos volvieron a poderse facturar.
            </div>

            <p v-if="factura.sustituye" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sustituye a la
                <a :href="`/finanzas/facturas/${factura.sustituye.id}`" :style="{ color: 'var(--color-acento)' }">
                    factura {{ factura.sustituye.uuid ?? factura.sustituye.id }}</a>.
            </p>
            <p v-for="s in factura.sustituida_por" :key="s.id" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sustituida por la
                <a :href="`/finanzas/facturas/${s.id}`" :style="{ color: 'var(--color-acento)' }">
                    factura {{ s.uuid ?? s.id }}</a>.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    v-if="factura.tiene_xml"
                    :href="`/finanzas/facturas/${factura.id}/descargar/xml`"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Descargar XML
                </a>
                <a
                    v-if="factura.tiene_pdf"
                    :href="`/finanzas/facturas/${factura.id}/descargar/pdf`"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Descargar PDF
                </a>
                <button
                    v-if="!factura.fiscal && factura.estatus !== 'timbrando'"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="reintentar"
                >
                    Reintentar timbrado
                </button>
                <button
                    v-if="factura.estatus === 'timbrada'"
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="refacturando = !refacturando"
                >
                    Refacturar con datos corregidos
                </button>
                <button
                    v-if="factura.estatus === 'timbrada'"
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm text-red-600"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="cancelando = !cancelando"
                >
                    Cancelar ante el SAT
                </button>
                <button
                    v-if="factura.editable"
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm text-red-600"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="eliminar"
                >
                    Eliminar borrador
                </button>
            </div>

            <!--
                Corregir una factura timbrada son DOS pasos y en este orden: el
                SAT pide el folio de la sustituta al cancelar, así que primero
                nace la nueva y solo entonces se cancela la vieja con motivo 01.
                Al revés, la escuela se queda sin comprobante vigente en el
                hueco entre las dos operaciones.
            -->
            <form
                v-if="refacturando"
                class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="refacturar"
            >
                <p class="text-sm sm:col-span-2" :style="{ color: 'var(--color-suave)' }">
                    Se emite un comprobante nuevo por los mismos pagos, ligado a éste. Cuando el PAC le dé
                    folio, vuelve aquí y cancela éste con motivo 01.
                </p>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">RFC</span>
                    <input v-model="refactura.rfc" type="text" required maxlength="13" class="w-full rounded-lg border px-3 py-2 font-mono text-sm uppercase" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Razón social</span>
                    <input v-model="refactura.razon_social" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Uso del CFDI</span>
                    <input v-model="refactura.uso_cfdi" type="text" required maxlength="5" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Régimen fiscal</span>
                    <input v-model="refactura.regimen_fiscal" type="text" required maxlength="5" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">CP fiscal</span>
                    <input v-model="refactura.cp" type="text" required maxlength="5" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <button
                    type="submit"
                    class="self-end rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Emitir la sustituta
                </button>
            </form>

            <form v-if="cancelando" class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[1fr_auto_auto]" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="cancelar">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Motivo de cancelación (SAT)</span>
                    <select
                        v-model="cancelacion.motivo"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option v-for="m in motivos" :key="m.valor" :value="m.valor">{{ m.etiqueta }}</option>
                    </select>
                    <span v-if="cancelacion.motivo === '01'" class="text-xs text-amber-700">
                        El motivo 01 exige que ya exista la factura que la sustituye. Emítela primero y
                        captura su id aquí.
                    </span>
                </label>
                <label v-if="cancelacion.motivo === '01'" class="text-sm">
                    <span class="mb-1 block font-medium">Id de la sustituta</span>
                    <input
                        v-model.number="cancelacion.sustituta_id"
                        type="number"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <button
                    type="submit"
                    class="self-end rounded-lg px-4 py-2 text-sm font-medium text-white"
                    style="background-color: #dc2626"
                >
                    Cancelar factura
                </button>
            </form>
        </section>

        <!--
            El emisor se muestra porque la escuela puede tener varias razones
            sociales: sin verlo, "por qué esta factura salió con el RFC de la
            otra" no tiene respuesta en pantalla.
        -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Emisor</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">RFC</dt>
                    <dd class="font-mono">{{ factura.emisor_rfc ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt :style="{ color: 'var(--color-suave)' }">Razón social</dt>
                    <dd>{{ factura.emisor_razon_social ?? '—' }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Régimen · CP</dt>
                    <dd>{{ factura.emisor_regimen_fiscal ?? '—' }} · {{ factura.emisor_cp ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Receptor</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">RFC</dt>
                    <dd class="font-mono">{{ factura.receptor_rfc }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt :style="{ color: 'var(--color-suave)' }">Razón social</dt>
                    <dd>{{ factura.receptor_razon_social }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Uso del CFDI</dt>
                    <dd>{{ factura.receptor_uso_cfdi }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Régimen fiscal</dt>
                    <dd>{{ factura.receptor_regimen_fiscal }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">CP fiscal</dt>
                    <dd>{{ factura.receptor_cp }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Forma de pago</dt>
                    <dd>{{ factura.forma_pago_sat ?? '—' }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Método de pago</dt>
                    <dd>{{ factura.metodo_pago_sat }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">PAC</dt>
                    <dd>{{ factura.pac ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold">Conceptos</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Un renglón por pago. La descripción y la clave del SAT se copiaron al emitir: si la
                    escuela renombra el concepto, este comprobante sigue diciendo lo que se timbró.
                </p>
            </header>

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Clave SAT</th>
                        <th class="px-4 py-3 font-medium">Descripción</th>
                        <th class="px-4 py-3 font-medium">Pago</th>
                        <th class="px-4 py-3 text-right font-medium">Cantidad</th>
                        <th class="px-4 py-3 text-right font-medium">Importe</th>
                        <th class="px-6 py-3 text-right font-medium">IVA</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in conceptos" :key="c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3 font-mono text-xs">{{ c.clave_sat }}</td>
                        <td class="px-4 py-3">{{ c.descripcion }}</td>
                        <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            #{{ c.pago_id }} <span v-if="c.pago_metodo">· {{ c.pago_metodo }}</span>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ c.cantidad }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(c.importe) }}</td>
                        <td class="px-6 py-3 text-right tabular-nums">{{ pesos.format(c.iva) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td colspan="4" class="px-6 py-2 text-right" :style="{ color: 'var(--color-suave)' }">Subtotal</td>
                        <td colspan="2" class="px-6 py-2 text-right tabular-nums">{{ pesos.format(factura.subtotal) }}</td>
                    </tr>
                    <tr>
                        <td colspan="4" class="px-6 py-2 text-right" :style="{ color: 'var(--color-suave)' }">IVA</td>
                        <td colspan="2" class="px-6 py-2 text-right tabular-nums">{{ pesos.format(factura.iva) }}</td>
                    </tr>
                    <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td colspan="4" class="px-6 py-3 text-right font-semibold">Total</td>
                        <td colspan="2" class="px-6 py-3 text-right text-base font-semibold tabular-nums">
                            {{ pesos.format(factura.total) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </section>
    </AppLayout>
</template>
