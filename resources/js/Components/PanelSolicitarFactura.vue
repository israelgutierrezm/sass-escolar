<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Solicitar factura: el alumno o su familia elige qué operaciones facturar y con
 * qué datos, y la escuela la emite. No emite el CFDI —eso es del personal—: deja
 * la solicitud. El mismo panel sirve al estado de cuenta y al portal del padre.
 */
interface PagoFacturable {
    id: number;
    monto: number;
    metodo: string | null;
    momento: string | null;
    concepto: string | null;
}

const props = defineProps<{
    matriculaId: number;
    pagos: PagoFacturable[];
    receptor: {
        rfc: string | null; razon_social: string | null; uso_cfdi: string | null;
        regimen_fiscal: string | null; cp: string | null; correo: string | null;
    } | null;
    catalogos: {
        usos_cfdi: { clave: string; texto: string }[];
        regimenes: { clave: string; texto: string }[];
    };
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const form = useForm({
    pago_ids: [] as number[],
    rfc: props.receptor?.rfc ?? '',
    razon_social: props.receptor?.razon_social ?? '',
    uso_cfdi: props.receptor?.uso_cfdi ?? 'D10',
    regimen_fiscal: props.receptor?.regimen_fiscal ?? '',
    cp: props.receptor?.cp ?? '',
    correo: props.receptor?.correo ?? '',
});

const total = computed(() =>
    props.pagos.filter((p) => form.pago_ids.includes(p.id)).reduce((s, p) => s + p.monto, 0),
);

function solicitar(): void {
    form.post(`/finanzas/cuentas/${props.matriculaId}/solicitar-factura`, {
        preserveScroll: true,
        onSuccess: () => form.reset('pago_ids'),
    });
}
</script>

<template>
    <div>
        <p v-if="!pagos.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
            No hay operaciones por facturar: tus pagos confirmados ya tienen factura, o todavía no hay pagos.
        </p>

        <form v-else @submit.prevent="solicitar">
            <p class="mb-2 text-sm font-medium">¿Qué operaciones quieres facturar?</p>
            <div class="space-y-1">
                <label
                    v-for="p in pagos"
                    :key="p.id"
                    class="flex items-center gap-3 rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <input v-model="form.pago_ids" type="checkbox" :value="p.id" />
                    <span class="flex-1">
                        {{ p.concepto ?? 'Pago' }}
                        <span :style="{ color: 'var(--color-suave)' }">· {{ p.metodo ?? '' }} · {{ p.momento ?? '' }}</span>
                    </span>
                    <span class="tabular-nums font-medium">{{ pesos.format(p.monto) }}</span>
                </label>
            </div>
            <p v-if="form.errors.pago_ids" class="mt-1 text-xs text-red-600">{{ form.errors.pago_ids }}</p>

            <p class="mt-4 mb-2 text-sm font-medium">Tus datos fiscales</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">RFC</span>
                    <input v-model="form.rfc" type="text" required class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.rfc" class="text-xs text-red-600">{{ form.errors.rfc }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Razón social / nombre</span>
                    <input v-model="form.razon_social" type="text" required class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.razon_social" class="text-xs text-red-600">{{ form.errors.razon_social }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Régimen fiscal</span>
                    <select v-model="form.regimen_fiscal" required class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                        <option value="" disabled>Elige el régimen</option>
                        <option v-for="r in catalogos.regimenes" :key="r.clave" :value="r.clave">{{ r.texto }}</option>
                    </select>
                    <span v-if="form.errors.regimen_fiscal" class="text-xs text-red-600">{{ form.errors.regimen_fiscal }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Uso del CFDI</span>
                    <select v-model="form.uso_cfdi" required class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }">
                        <option v-for="u in catalogos.usos_cfdi" :key="u.clave" :value="u.clave">{{ u.texto }}</option>
                    </select>
                    <span v-if="form.errors.uso_cfdi" class="text-xs text-red-600">{{ form.errors.uso_cfdi }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Código postal fiscal</span>
                    <input v-model="form.cp" type="text" required maxlength="5" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.cp" class="text-xs text-red-600">{{ form.errors.cp }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Correo para recibirla (opcional)</span>
                    <input v-model="form.correo" type="email" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="form.errors.correo" class="text-xs text-red-600">{{ form.errors.correo }}</span>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    :disabled="form.processing || !form.pago_ids.length"
                >
                    {{ form.processing ? 'Enviando…' : `Solicitar factura de ${pesos.format(total)}` }}
                </button>
                <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    La escuela la revisa y la emite. No es un CFDI todavía.
                </span>
            </div>
        </form>
    </div>
</template>
