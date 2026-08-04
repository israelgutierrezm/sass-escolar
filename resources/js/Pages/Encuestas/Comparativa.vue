<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/**
 * Cómo cambió el mismo cuestionario de un ciclo al siguiente.
 *
 * ── Por qué esta pantalla existe ───────────────────────────────────────────
 * «4.1 sobre 5» no dice si la escuela va bien: dice que va en 4.1. Lo que
 * permite decidir es si subió o bajó, y eso sólo se ve poniendo las
 * aplicaciones una junto a otra.
 *
 * La variación se toma entre la PRIMERA y la ÚLTIMA, no contra el semestre
 * inmediato anterior: comparar sólo con el anterior esconde una caída sostenida
 * de tres ciclos, que es justo lo que hay que ver.
 */
interface FilaPregunta {
    pregunta: string;
    valores: (number | null)[];
    variacion: number | null;
    completa: boolean;
}

const props = defineProps<{
    aplicacion: { id: number; titulo: string };
    comparativa: {
        aplicaciones: { id: number; titulo: string; respuestas: number; cerrada_en: string | null }[];
        preguntas: FilaPregunta[];
        general: { valores: (number | null)[]; variacion: number | null };
    };
}>();

/** Verde si mejoró, rojo si empeoró, gris si se movió poco o no hay con qué. */
function colorVariacion(variacion: number | null): string {
    if (variacion === null || Math.abs(variacion) < 0.1) return 'var(--color-suave)';

    return variacion > 0 ? '#16a34a' : '#dc2626';
}

function conSigno(variacion: number | null): string {
    if (variacion === null) return '—';

    return `${variacion > 0 ? '+' : ''}${variacion.toFixed(2)}`;
}
</script>

<template>
    <Head title="Comparativa" />

    <AppLayout titulo="Comparativa entre aplicaciones">
        <BotonVolver :href="`/encuestas/aplicaciones/${aplicacion.id}`" texto="La encuesta" class="mb-4" />

        <section
            v-if="comparativa.aplicaciones.length < 2"
            class="tarjeta px-6 py-12 text-center"
        >
            <p class="text-sm text-contenido">Todavía no hay con qué comparar.</p>
            <p class="mx-auto mt-1 max-w-lg text-xs text-suave">
                La comparativa necesita al menos dos aplicaciones del mismo cuestionario. Al aplicar
                otra vez «{{ aplicacion.titulo }}» —el siguiente ciclo, por ejemplo— esta pantalla
                mostrará cómo cambió cada pregunta.
            </p>
        </section>

        <template v-else>
            <!-- El promedio general, que es lo primero que se pregunta. -->
            <section class="tarjeta mb-4 p-5">
                <h2 class="text-sm font-semibold text-contenido">Promedio general</h2>

                <div class="mt-3 flex flex-wrap items-end gap-6">
                    <div v-for="(v, i) in comparativa.general.valores" :key="i">
                        <p class="text-xs text-suave">{{ comparativa.aplicaciones[i]?.titulo }}</p>
                        <p class="text-2xl font-semibold tabular-nums" :style="{ color: 'var(--color-acento)' }">
                            {{ v ?? '—' }}
                        </p>
                        <p class="text-xs text-suave">
                            {{ comparativa.aplicaciones[i]?.respuestas }} respuestas
                        </p>
                    </div>

                    <div v-if="comparativa.general.variacion !== null" class="ml-auto text-right">
                        <p class="text-xs text-suave">Variación</p>
                        <p class="text-2xl font-semibold tabular-nums" :style="{ color: colorVariacion(comparativa.general.variacion) }">
                            {{ conSigno(comparativa.general.variacion) }}
                        </p>
                    </div>
                </div>
            </section>

            <section class="tarjeta overflow-hidden">
                <h2 class="border-b border-borde px-5 py-3 text-sm font-semibold text-contenido">Por pregunta</h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-suave">
                            <tr class="border-b border-borde">
                                <th class="px-5 py-2 font-medium">Pregunta</th>
                                <th
                                    v-for="a in comparativa.aplicaciones"
                                    :key="a.id"
                                    class="py-2 text-right font-medium"
                                >
                                    {{ a.titulo }}
                                </th>
                                <th class="py-2 pr-5 text-right font-medium">Variación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(f, i) in comparativa.preguntas" :key="i" class="border-b border-borde last:border-0">
                                <td class="px-5 py-2.5">
                                    {{ f.pregunta }}
                                    <!-- Lo que sólo existe en una de las dos se
                                         señala: es la explicación de por qué esa
                                         fila no tiene variación. -->
                                    <span v-if="! f.completa" class="ml-1 text-xs text-suave">
                                        (no estaba en todas)
                                    </span>
                                </td>
                                <td
                                    v-for="(v, j) in f.valores"
                                    :key="j"
                                    class="py-2.5 text-right tabular-nums"
                                    :class="{ 'text-suave': v === null }"
                                >
                                    {{ v ?? '—' }}
                                </td>
                                <td class="py-2.5 pr-5 text-right font-semibold tabular-nums" :style="{ color: colorVariacion(f.variacion) }">
                                    {{ conSigno(f.variacion) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="! comparativa.preguntas.length" class="px-5 py-8 text-center text-xs text-suave">
                    No hay preguntas comparables: sólo se comparan las que dan un número —escalas y
                    cantidades—, porque las opciones y los textos no se pueden restar.
                </p>
            </section>
        </template>
    </AppLayout>
</template>
