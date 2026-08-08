<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Vuelta de la pasarela.
 *
 * Lo que se ve aquí es el estado REAL del cobro, no «gracias por su pago»: esta
 * pantalla la abre cualquiera escribiendo la dirección, así que el servidor le
 * pregunta a la pasarela antes de pintarla. Un pago en proceso se dice que está
 * en proceso, porque prometerle a alguien que ya pagó cuando el banco todavía lo
 * está pensando es la clase de mentira que se descubre en la ventanilla.
 */
const props = defineProps<{
    estado: string;
    mensaje: string;
    monto?: number;
    volver: string | null;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const aspecto = computed(() => {
    switch (props.estado) {
        case 'aprobado':
            return { color: '#16a34a', titulo: 'Pago recibido', icono: 'm4.5 12.75 6 6 9-13.5' };
        case 'rechazado':
            return { color: '#dc2626', titulo: 'Pago rechazado', icono: 'M6 18 18 6M6 6l12 12' };
        case 'cancelado':
            return { color: '#6b7280', titulo: 'Pago cancelado', icono: 'M6 18 18 6M6 6l12 12' };
        default:
            return { color: '#d97706', titulo: 'Pago en proceso', icono: 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' };
    }
});
</script>

<template>
    <Head title="Resultado del pago" />

    <AppLayout titulo="Pago">
        <div class="mx-auto max-w-lg">
            <div class="tarjeta px-6 py-10 text-center">
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full"
                    :style="{ backgroundColor: `color-mix(in srgb, ${aspecto.color} 14%, transparent)` }"
                >
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="2" :stroke="aspecto.color">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="aspecto.icono" />
                    </svg>
                </div>

                <h2 class="mt-4 text-lg font-semibold">{{ aspecto.titulo }}</h2>

                <p v-if="monto" class="mt-1 text-2xl font-semibold">{{ pesos.format(monto) }}</p>

                <p class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">{{ mensaje }}</p>

                <Link
                    v-if="volver"
                    :href="volver"
                    class="mt-6 inline-block rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    Ver mi estado de cuenta
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
