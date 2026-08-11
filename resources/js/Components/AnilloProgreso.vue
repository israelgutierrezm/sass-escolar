<script setup lang="ts">
import { computed } from 'vue';

/**
 * Un anillo de progreso con su porcentaje dentro.
 *
 * ── Por qué SVG y no una barra ────────────────────────────────────────────
 * En un renglón de tabla no hay ancho para una barra que se entienda: una de
 * 60 px no distingue el 40 % del 55 %. Un anillo dice lo mismo en el alto de
 * la fila y deja sitio para la cifra en el centro, que es la que se lee
 * cuando importa el número exacto.
 *
 * ── El color lo pone el TEMA, salvo que se diga otra cosa ─────────────────
 * Por omisión `--color-acento` y no una paleta propia: el anillo aparece junto
 * a píldoras y botones que ya usan el acento de la escuela, y meterle un azul
 * fijo lo volvería lo único que no combina. Quien lo monta puede pasar un color
 * cuando el estado lo pida —verde al llegar a la meta—, porque ahí el color ES
 * el dato y no decoración.
 *
 * ── Se dibuja con `stroke-dasharray`, sin librería ────────────────────────
 * La circunferencia es 2πr; se pinta la fracción que toca y se deja el resto
 * en el hueco. Es una sola línea de cuenta y evita traer un paquete entero
 * para dibujar un círculo.
 */
const props = withDefaults(defineProps<{
    /** 0 a 100. Se acota, porque un dato malo no debe dibujar un anillo raro. */
    porcentaje: number;
    /** Diámetro en píxeles. */
    tamano?: number;
    /** Grosor del trazo. */
    grosor?: number;
    /** Lo que se lee debajo de la cifra: «3 de 5». */
    detalle?: string | null;
    /** Título accesible; si no viene, se arma con el porcentaje. */
    titulo?: string | null;
    /** Color del trazo. Sin él, el acento de la escuela. */
    color?: string;
}>(), {
    tamano: 44,
    grosor: 4,
    detalle: null,
    titulo: null,
    color: 'var(--color-acento)',
});

const valor = computed(() => Math.min(100, Math.max(0, Math.round(props.porcentaje))));

const radio = computed(() => (props.tamano - props.grosor) / 2);
const circunferencia = computed(() => 2 * Math.PI * radio.value);
const recorrido = computed(() => (circunferencia.value * valor.value) / 100);
</script>

<template>
    <div
        class="relative shrink-0"
        :style="{ width: `${tamano}px`, height: `${tamano}px` }"
        :title="titulo ?? `${valor}%${detalle ? ` · ${detalle}` : ''}`"
    >
        <!-- `-rotate-90`: el trazo de un SVG arranca a las 3 en punto y un
             progreso se lee desde arriba. -->
        <svg class="-rotate-90" :width="tamano" :height="tamano" aria-hidden="true">
            <circle
                :cx="tamano / 2" :cy="tamano / 2" :r="radio"
                fill="none" :stroke-width="grosor"
                :stroke="`color-mix(in srgb, ${color} 18%, transparent)`"
            />
            <circle
                v-if="valor > 0"
                :cx="tamano / 2" :cy="tamano / 2" :r="radio"
                fill="none" :stroke-width="grosor" stroke-linecap="round"
                :stroke="color"
                :stroke-dasharray="`${recorrido} ${circunferencia}`"
                class="transition-all duration-500"
            />
        </svg>

        <span class="absolute inset-0 grid place-items-center leading-none">
            <span
                class="font-semibold tabular-nums"
                :style="{ fontSize: `${Math.max(9, Math.round(tamano * 0.26))}px`, color: 'var(--color-contenido)' }"
            >{{ valor }}%</span>
        </span>
    </div>
</template>
