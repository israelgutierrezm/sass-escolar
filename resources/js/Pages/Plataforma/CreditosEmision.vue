<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Los créditos de emisión de la escuela: cuánto queda, qué se ha usado y cómo
 * comprar más.
 *
 * ── Aquí sólo se mira y se pide ────────────────────────────────────────────
 * La modalidad la decide la organización que administra la plataforma, no la
 * escuela; y los créditos se acreditan cuando alguien de allá valida el
 * comprobante. Se dice claro para que nadie espere que el saldo suba solo.
 */
defineProps<{
    saldo: {
        modalidad: string;
        etiqueta: string;
        creditos: number;
        cuenta_creditos: boolean;
        explicacion: string;
    };
    resumen: {
        emitidos: number;
        cobrados: number;
        regenerados: number;
        certificados: number;
        titulos: number;
    };
    ultimos: {
        tipo: string; curp: string; plan: string; referencia: string | null;
        cobrado: boolean; cuando: string | null;
    }[];
    compras: {
        id: number; creditos: number; monto: number | null; referencia: string | null;
        estado: string; motivo_rechazo: string | null; cuando: string | null; revisado_en: string | null;
    }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const comprando = ref(false);

const form = useForm({
    creditos: 100 as number | string,
    monto: '' as number | string,
    referencia: '',
    comprobante: null as File | null,
});

function elegirArchivo(e: Event): void {
    form.comprobante = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function enviar(): void {
    form.post('/plataforma/creditos/comprar', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { form.reset(); comprando.value = false; },
    });
}
</script>

<template>
    <Head title="Créditos de emisión" />

    <AppLayout titulo="Créditos de emisión">
        <p class="mb-4 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Lo que cuesta emitir los XML de certificación y titulación. {{ saldo.explicacion }}
        </p>

        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div v-if="saldo.cuenta_creditos" class="tarjeta p-5">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Créditos disponibles</p>
                <p class="text-3xl font-semibold" :class="saldo.creditos <= 0 ? 'text-red-600' : ''">
                    {{ saldo.creditos }}
                </p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Modalidad</p>
                <p class="text-lg font-semibold">{{ saldo.etiqueta }}</p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">XML emitidos</p>
                <p class="text-3xl font-semibold">{{ resumen.emitidos }}</p>
                <!--
                    El total dice cuánto se gastó, no en qué. Son dos trámites
                    distintos, con dos áreas distintas detrás, y la primera
                    pregunta al ver la cifra es siempre de cuál son.
                -->
                <p v-if="resumen.emitidos > 0" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ resumen.certificados }} de certificación · {{ resumen.titulos }} de titulación
                </p>
            </div>
            <div class="tarjeta p-5">
                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Cobrados</p>
                <p class="text-3xl font-semibold">{{ resumen.cobrados }}</p>
                <!--
                    Lo rehecho no cuesta: se dice porque es justo la duda que
                    tiene quien ve dos números distintos.
                -->
                <p v-if="resumen.regenerados" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ resumen.regenerados }} {{ resumen.regenerados === 1 ? 'rehecho' : 'rehechos' }}, sin costo
                </p>
            </div>
        </div>

        <!-- Comprar -->
        <section v-if="saldo.cuenta_creditos" class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Comprar créditos</h2>
                <button
                    v-if="!comprando"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="comprando = true"
                >
                    Reportar un pago
                </button>
            </div>

            <template v-if="comprando">
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Créditos comprados</span>
                        <input v-model="form.creditos" type="number" min="1" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                        <span v-if="form.errors.creditos" class="text-xs text-red-600">{{ form.errors.creditos }}</span>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Importe pagado (opcional)</span>
                        <input v-model="form.monto" type="number" step="0.01" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Referencia (opcional)</span>
                        <input v-model="form.referencia" type="text" class="w-full rounded-lg border bg-transparent px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                </div>

                <label class="mt-3 block text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Comprobante del pago</span>
                    <input type="file" accept="image/*,application/pdf" class="w-full text-sm" @change="elegirArchivo" />
                    <span v-if="form.errors.comprobante" class="text-xs text-red-600">{{ form.errors.comprobante }}</span>
                </label>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        :disabled="form.processing"
                        @click="enviar"
                    >
                        {{ form.processing ? 'Enviando…' : 'Enviar comprobante' }}
                    </button>
                    <button type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="comprando = false">
                        Cancelar
                    </button>
                </div>

                <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Los créditos NO se acreditan al enviarlo: alguien de la plataforma valida el
                    comprobante y entonces aparecen en tu saldo.
                </p>
            </template>
        </section>

        <!-- Compras -->
        <section v-if="compras.length" class="tarjeta mb-4 overflow-hidden">
            <h2 class="px-6 py-4 font-semibold">Compras</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                        <th class="px-4 py-2 font-semibold">Fecha</th>
                        <th class="px-4 py-2 text-right font-semibold">Créditos</th>
                        <th class="px-4 py-2 text-right font-semibold">Importe</th>
                        <th class="px-4 py-2 font-semibold">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in compras" :key="c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-4 py-2">{{ c.cuando }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ c.creditos }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ c.monto === null ? '—' : pesos.format(c.monto) }}</td>
                        <td class="px-4 py-2">
                            <span class="capitalize">{{ c.estado }}</span>
                            <p v-if="c.motivo_rechazo" class="text-xs text-red-600">{{ c.motivo_rechazo }}</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Consumo -->
        <section class="tarjeta overflow-hidden">
            <h2 class="px-6 py-4 font-semibold">Últimos XML emitidos</h2>
            <table v-if="ultimos.length" class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                        <th class="px-4 py-2 font-semibold">Cuándo</th>
                        <th class="px-4 py-2 font-semibold">Tipo</th>
                        <th class="px-4 py-2 font-semibold">Alumno (CURP)</th>
                        <th class="px-4 py-2 font-semibold">Plan</th>
                        <th class="px-4 py-2 font-semibold">Costo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(u, i) in ultimos" :key="i" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-4 py-2">{{ u.cuando }}</td>
                        <td class="px-4 py-2 capitalize">{{ u.tipo }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ u.curp }}</td>
                        <td class="px-4 py-2">{{ u.plan }}</td>
                        <td class="px-4 py-2">
                            <span v-if="u.cobrado">1 crédito</span>
                            <span v-else :style="{ color: 'var(--color-suave)' }">rehecho, sin costo</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se ha emitido ningún XML.
            </p>
        </section>
    </AppLayout>
</template>
