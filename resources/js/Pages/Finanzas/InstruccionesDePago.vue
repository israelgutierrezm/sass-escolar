<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Cómo pagar, cuando la pasarela devuelve datos en vez de una página.
 *
 * Es el caso de la transferencia SPEI: no hay dónde pagar, hay una CLABE y una
 * referencia que se teclean en la banca de cada quien. Son datos que se copian
 * a mano y un dígito de más arruina el pago, así que cada uno lleva su botón de
 * copiar en vez de confiar en que se seleccione bien con el dedo.
 */
defineProps<{
    monto: number;
    pasarela: string;
    datos: { etiqueta: string; valor: string }[];
    volver: string;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const copiado = ref<string | null>(null);

async function copiar(etiqueta: string, valor: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(valor);
        copiado.value = etiqueta;
        setTimeout(() => { copiado.value = null; }, 2000);
    } catch {
        // Sin permiso de portapapeles no pasa nada: el dato sigue a la vista y
        // se puede seleccionar a mano.
    }
}
</script>

<template>
    <Head title="Cómo pagar" />

    <AppLayout titulo="Cómo pagar">
        <div class="mx-auto max-w-lg">
            <div class="tarjeta px-6 py-6">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">Importe a pagar</p>
                <p class="text-3xl font-semibold">{{ pesos.format(monto) }}</p>

                <p class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Haz la transferencia con estos datos desde tu banca en línea. El pago se aplica
                    solo, en cuanto el banco lo confirme: no hace falta mandar comprobante.
                </p>

                <dl class="mt-5 divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <div v-for="d in datos" :key="d.etiqueta" class="flex items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <dt class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                                {{ d.etiqueta }}
                            </dt>
                            <dd class="break-all font-mono text-sm">{{ d.valor }}</dd>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="copiar(d.etiqueta, d.valor)"
                        >
                            {{ copiado === d.etiqueta ? 'Copiado' : 'Copiar' }}
                        </button>
                    </div>
                </dl>

                <p v-if="!datos.length" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    La pasarela no devolvió los datos para pagar. Vuelve a intentarlo o pide ayuda en
                    la escuela.
                </p>

                <Link
                    :href="volver"
                    class="mt-6 inline-block rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Ver el estado de cuenta
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
