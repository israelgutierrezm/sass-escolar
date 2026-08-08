<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * La pasarela de mentira.
 *
 * Ocupa el lugar donde iría Mercado Pago para poder recorrer el cobro entero
 * —incluido el aviso que confirma— sin credenciales y sin cobrarle a nadie. Lo
 * que se elige aquí entra por el mismo camino que un aviso de verdad; no hay
 * atajo que salte la conciliación.
 *
 * Sólo existe con el cobro en línea en modo de pruebas: fuera de él, el
 * servidor responde 404.
 */
defineProps<{
    intencion: { id: number; monto: number; pasarela: string; estado: string };
    estados: { valor: string; texto: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const enviando = ref<string | null>(null);

function resolver(estado: string, intencionId: number): void {
    enviando.value = estado;

    router.post(`/pagos/simulador/${intencionId}`, { estado }, {
        onFinish: () => { enviando.value = null; },
    });
}
</script>

<template>
    <Head title="Simulador de pago" />

    <AppLayout titulo="Simulador de pago">
        <div class="mx-auto max-w-lg">
            <div
                class="mb-4 rounded-lg border px-4 py-3 text-sm"
                :style="{ borderColor: '#d97706', color: '#b45309', backgroundColor: 'color-mix(in srgb, #d97706 8%, transparent)' }"
            >
                Esto no es una pasarela real. El cobro en línea está en modo de pruebas, así que
                aquí se elige cómo termina el pago.
            </div>

            <div class="tarjeta px-6 py-6">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Cobro #{{ intencion.id }} · {{ intencion.pasarela }}
                </p>
                <p class="mt-1 text-3xl font-semibold">{{ pesos.format(intencion.monto) }}</p>

                <div class="mt-6 space-y-2">
                    <button
                        v-for="e in estados"
                        :key="e.valor"
                        type="button"
                        class="w-full rounded-lg border px-4 py-3 text-left text-sm transition hover:brightness-105 disabled:opacity-60"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        :disabled="enviando !== null"
                        @click="resolver(e.valor, intencion.id)"
                    >
                        {{ enviando === e.valor ? 'Enviando…' : e.texto }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
