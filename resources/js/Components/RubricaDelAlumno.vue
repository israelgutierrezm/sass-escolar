<script setup lang="ts">
import { computed } from 'vue';
import type { EvaluacionPorCriterio, RubricaDeActividad } from '@/utils/rubrica';

/**
 * La rúbrica tal como la ve el alumno: antes de entregar y después de la nota.
 *
 * ── Se enseña ANTES, no sólo con la calificación ───────────────────────────
 * Es a lo que sirve una rúbrica. Leer «para el nivel alto hay que sostener la
 * tesis con al menos dos fuentes» antes de escribir cambia lo que se entrega;
 * leerlo después sólo explica un 7 que ya no se puede mover. Si se mostrara sólo
 * al calificar, la escuela habría redactado los descriptores para nada.
 *
 * ── Ya calificado, se marca el nivel obtenido y se apagan los demás ────────
 * No se esconden: ver dónde quedó uno respecto de lo que había arriba es la
 * mitad de la información. Tachar los otros dejaría «te dieron 2 de 4» sin decir
 * qué faltaba para los 4.
 */
const props = defineProps<{
    rubrica: RubricaDeActividad;
    /** Lo evaluado, si ya está calificado. Vacío = todavía no. */
    evaluacion?: EvaluacionPorCriterio[];
    /** Sobre cuántos puntos va la actividad, para explicar la conversión. */
    puntosActividad: number;
}>();

const calificado = computed(() => (props.evaluacion ?? []).some((e) => e.nivel_id !== null));

function evaluacionDe(criterioId: number): EvaluacionPorCriterio | undefined {
    return (props.evaluacion ?? []).find((e) => e.criterio_id === criterioId);
}

const obtenido = computed(() =>
    Math.round((props.evaluacion ?? []).reduce((suma, e) => suma + (e.puntos ?? 0), 0) * 100) / 100,
);
</script>

<template>
    <section class="rounded-xl border" :style="{ borderColor: 'var(--color-borde)' }">
        <header class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3">
            <span>
                <strong class="text-sm text-contenido">
                    {{ calificado ? 'Cómo te calificaron' : 'Con qué se va a calificar' }}
                </strong>
                <span class="ml-1.5 text-xs text-suave">{{ rubrica.nombre }}</span>
            </span>
            <span v-if="calificado" class="text-xs text-suave">
                <strong class="text-contenido">{{ obtenido }}</strong> de {{ rubrica.total }}
            </span>
            <span v-else class="text-xs text-suave">{{ rubrica.total }} puntos en total</span>
        </header>

        <div
            v-for="c in rubrica.criterios"
            :key="c.id"
            class="border-t px-4 py-3"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-sm font-medium text-contenido">{{ c.titulo }}</span>
                <span class="shrink-0 text-xs text-suave">
                    <template v-if="calificado">
                        {{ evaluacionDe(c.id)?.puntos ?? 0 }} de {{ c.maximo }}
                    </template>
                    <template v-else>hasta {{ c.maximo }}</template>
                </span>
            </div>
            <p v-if="c.descripcion" class="mt-0.5 text-xs text-suave">{{ c.descripcion }}</p>

            <div class="mt-1.5 grid gap-1.5 sm:grid-cols-2">
                <div
                    v-for="n in c.niveles"
                    :key="n.id"
                    class="rounded-lg border px-2.5 py-2 transition"
                    :style="{
                        borderColor: evaluacionDe(c.id)?.nivel_id === n.id
                            ? 'var(--color-acento)'
                            : 'var(--color-borde)',
                        backgroundColor: evaluacionDe(c.id)?.nivel_id === n.id
                            ? 'color-mix(in srgb, var(--color-acento) 10%, transparent)'
                            : 'transparent',
                        // Atenuado, no escondido: dónde quedó uno respecto de lo
                        // que había arriba es la mitad de la información.
                        opacity: calificado && evaluacionDe(c.id)?.nivel_id !== n.id ? 0.5 : 1,
                    }"
                >
                    <span class="flex items-baseline justify-between gap-2">
                        <strong class="text-xs text-contenido">{{ n.titulo }}</strong>
                        <span
                            class="text-xs font-semibold tabular-nums"
                            :style="{ color: 'var(--color-acento)' }"
                        >{{ n.puntos }}</span>
                    </span>
                    <span v-if="n.descripcion" class="mt-0.5 block text-[11px] leading-snug text-suave">
                        {{ n.descripcion }}
                    </span>
                </div>
            </div>

            <!-- Lo que el docente escribió en ESTE criterio. Va aquí y no al
                 pie con la retroalimentación general: es lo que explica por qué
                 no le tocó el nivel de arriba, y separado de su criterio hay que
                 adivinar a cuál se refiere. -->
            <p
                v-if="evaluacionDe(c.id)?.comentario"
                class="mt-1.5 rounded-lg px-2.5 py-1.5 text-xs text-contenido"
                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)' }"
            >
                {{ evaluacionDe(c.id)?.comentario }}
            </p>
        </div>

        <!-- La conversión, dicha. Sin esto, «5 de 6» junto a un «8.33 de 10» en
             otra parte de la pantalla parecen dos notas distintas. -->
        <p
            v-if="rubrica.total !== puntosActividad"
            class="border-t px-4 py-2.5 text-xs text-suave"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            La rúbrica va sobre {{ rubrica.total }} puntos y la actividad sobre
            {{ puntosActividad }}: la suma se convierte a esa escala.
        </p>
    </section>
</template>
