<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

defineProps<{
    vacante: { id: number; titulo: string };
    postulacion: { id: number; persona: string | null };
    movimientos: {
        origen: string | null;
        destino: string | null;
        quien: string;
        nota: string | null;
        momento: string | null;
    }[];
}>();
</script>

<template>
    <Head :title="`Historial · ${postulacion.persona}`" />

    <AppLayout :titulo="`Historial · ${postulacion.persona}`">
        <BotonVolver :href="`/bolsa/vacantes/${vacante.id}/postulaciones`" texto="Volver a los postulantes" class="mb-4" />

        <TarjetaSeccion :titulo="vacante.titulo" sin-relleno>
            <ol>
                <li
                    v-for="(m, i) in movimientos"
                    :key="i"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <p class="font-medium">
                        <!-- Sin origen = el alta. No se dibuja una flecha desde
                             la nada: no venía de ninguna etapa. -->
                        <template v-if="m.origen">{{ m.origen }} → </template>{{ m.destino }}
                    </p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ m.momento }} · {{ m.quien }}
                    </p>
                    <p v-if="m.nota" class="mt-1 whitespace-pre-line text-xs">{{ m.nota }}</p>
                </li>
            </ol>
        </TarjetaSeccion>
    </AppLayout>
</template>
