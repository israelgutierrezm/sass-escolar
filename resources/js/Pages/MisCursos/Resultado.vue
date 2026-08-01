<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/*
 * El resultado de un intento ya entregado.
 *
 * Lo que se ve aquí lo decide el SERVIDOR: si el examen todavía no publica
 * resultados, no llega ni el puntaje ni el detalle. Esconderlo en la pantalla y
 * mandarlo igual sería dejar el examen resuelto en el código de la página.
 */
defineProps<{
    actividad: { id: number; titulo: string };
    materia: { id: number };
    intento: { id: number; numero: number; entregado_en: string | null; requiere_revision: boolean };
    resultado: { puntos_obtenidos: number; puntos_posibles: number; en_diez: number | null } | null;
    detalle: {
        id: number;
        enunciado: string;
        puntos: number;
        obtenidos: number | null;
        correcta: boolean | null;
        retroalimentacion: string | null;
        comentario: string | null;
        esperada: string[];
    }[];
}>();

function colorNota(en: number | null): string {
    if (en === null) return 'var(--color-suave)';

    return en >= 8 ? '#16a34a' : en >= 6 ? '#d97706' : '#dc2626';
}
</script>

<template>
    <Head :title="actividad.titulo" />

    <AppLayout titulo="Resultado del examen">
        <section class="tarjeta p-6">
            <BotonVolver :href="`/mis-cursos/examenes/${actividad.id}`" texto="El examen" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-contenido">{{ actividad.titulo }}</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        Intento {{ intento.numero }}
                        <span v-if="intento.entregado_en"> · entregado el {{ intento.entregado_en }}</span>
                    </p>
                </div>

                <div v-if="resultado" class="text-right">
                    <p
                        class="text-3xl font-semibold leading-none tabular-nums"
                        :style="{ color: colorNota(resultado.en_diez) }"
                    >
                        {{ resultado.en_diez ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs text-suave">
                        {{ resultado.puntos_obtenidos }} de {{ resultado.puntos_posibles }} puntos
                    </p>
                </div>
            </div>

            <p
                v-if="intento.requiere_revision"
                class="mt-4 rounded-lg px-3 py-2 text-sm"
                :style="{ backgroundColor: 'color-mix(in srgb, #d97706 12%, transparent)', color: '#b45309' }"
            >
                Tu examen tiene preguntas que revisa el docente. En cuanto las
                califique vas a ver aquí tu resultado.
            </p>

            <p v-else-if="!resultado" class="mt-4 text-sm text-suave">
                Tu examen quedó entregado. El resultado se publica más adelante.
            </p>
        </section>

        <section v-if="detalle.length" class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold text-contenido">Reactivo por reactivo</h2>
            </header>

            <ul class="divide-y divide-borde border-t border-borde">
                <li v-for="(d, i) in detalle" :key="d.id" class="px-6 py-4">
                    <div class="flex flex-wrap items-start gap-4">
                        <span class="min-w-0 flex-1 text-sm">
                            <span class="mr-2 text-suave">{{ i + 1 }}.</span>{{ d.enunciado }}
                        </span>
                        <span
                            class="shrink-0 text-sm font-semibold tabular-nums"
                            :style="{
                                color: d.obtenidos === null
                                    ? 'var(--color-suave)'
                                    : d.obtenidos >= d.puntos
                                        ? '#16a34a'
                                        : d.obtenidos > 0
                                            ? '#d97706'
                                            : '#dc2626',
                            }"
                        >
                            {{ d.obtenidos ?? '—' }} / {{ d.puntos }}
                        </span>
                    </div>

                    <p v-if="d.esperada.length && d.correcta === false" class="mt-2 text-xs text-suave">
                        Lo correcto era: <strong>{{ d.esperada.join(', ') }}</strong>
                    </p>

                    <p
                        v-if="d.retroalimentacion || d.comentario"
                        class="mt-2 border-l-2 py-1 pl-3 text-sm"
                        :style="{ borderColor: 'var(--color-acento)' }"
                    >
                        {{ d.comentario ?? d.retroalimentacion }}
                    </p>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
