<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/**
 * Quién confirmó haber leído un aviso.
 *
 * Es lo que convierte un aviso crítico en algo comprobable y no en un acto de
 * fe: el día que alguien diga que nunca se enteró de la suspensión de clases,
 * esta lista responde.
 *
 * Se lista a quien lo VIO además de a quien lo CONFIRMÓ, porque son cosas
 * distintas: haberlo tenido delante no es haber declarado que se leyó.
 */
const props = defineProps<{
    aviso: { id: number; titulo: string; prioridad_etiqueta: string; color: string };
    lecturas: { quien: string; visto: string | null; confirmado: string | null }[];
}>();

/** El recuento en una frase, concordando en singular y en plural. */
const resumen = computed(() => {
    const vistas = props.lecturas.length;
    const confirmadas = props.lecturas.filter((l) => l.confirmado).length;

    if (vistas === 0) return '';

    if (vistas === 1) {
        return confirmadas === 1
            ? 'Una persona lo ha visto y confirmó haberlo leído.'
            : 'Una persona lo ha visto, pero todavía no confirma haberlo leído.';
    }

    const confirma = confirmadas === 1 ? '1 confirmó' : `${confirmadas} confirmaron`;

    return `Lo han visto ${vistas} personas; ${confirma} haberlo leído.`;
});
</script>

<template>
    <Head :title="`Lecturas · ${aviso.titulo}`" />

    <AppLayout titulo="Confirmaciones de lectura">
        <BotonVolver href="/plataforma/avisos" texto="Avisos" class="mb-4" />

        <section class="tarjeta mb-4 border-l-4 p-6" :style="{ borderLeftColor: aviso.color }">
            <span
                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                :style="{ backgroundColor: `color-mix(in srgb, ${aviso.color} 14%, transparent)`, color: aviso.color }"
            >
                {{ aviso.prioridad_etiqueta }}
            </span>
            <h2 class="mt-2 text-lg font-semibold text-contenido">{{ aviso.titulo }}</h2>
            <p v-if="resumen" class="mt-1 text-sm text-suave">{{ resumen }}</p>
        </section>

        <section class="tarjeta overflow-hidden">
            <table v-if="lecturas.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-suave">
                    <tr class="border-b border-borde">
                        <th class="px-5 py-2 font-medium">Persona</th>
                        <th class="py-2 font-medium">Lo vio</th>
                        <th class="py-2 pr-5 font-medium">Confirmó haberlo leído</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in lecturas" :key="i" class="border-b border-borde last:border-0">
                        <td class="px-5 py-2.5 font-medium">{{ l.quien }}</td>
                        <td class="py-2.5 tabular-nums text-suave">{{ l.visto ?? '—' }}</td>
                        <td class="py-2.5 pr-5 tabular-nums" :style="{ color: l.confirmado ? '#16a34a' : 'var(--color-suave)' }">
                            {{ l.confirmado ?? 'Todavía no' }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-5 py-12 text-center text-sm text-suave">
                Nadie lo ha visto todavía. Si el aviso está publicado y vigente, aparecerá aquí en
                cuanto alguien entre.
            </p>
        </section>
    </AppLayout>
</template>
