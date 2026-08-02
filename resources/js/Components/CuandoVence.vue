<script setup lang="ts">
import { computed } from 'vue';

/**
 * Cuándo vence algo, dicho como lo diría una persona.
 *
 * «Vence mañana» se entiende sin pensar; «2026-08-14 23:59» hay que restarlo
 * mentalmente contra el día de hoy, y ese cálculo es justo el que se hace mal
 * cuando uno va con prisa.
 *
 * Los días los cuenta el SERVIDOR y llegan ya resueltos: hacerlo en el navegador
 * ataría «vence hoy» al reloj de la computadora del alumno.
 */
const props = defineProps<{
    /** Días hasta el cierre. Null = sin fecha; negativo = ya pasó. */
    dias: number | null;
    /** La fecha exacta, para el título: lo cercano se dice, lo exacto se consulta. */
    fecha?: string | null;
    /** Si se sigue aceptando después de la fecha. */
    permiteTarde?: boolean;
}>();

const texto = computed(() => {
    if (props.dias === null) return 'Sin fecha límite';
    if (props.dias < -1) return `Venció hace ${Math.abs(props.dias)} días`;
    if (props.dias === -1) return 'Venció ayer';
    if (props.dias === 0) return 'Vence hoy';
    if (props.dias === 1) return 'Vence mañana';
    if (props.dias <= 7) return `Quedan ${props.dias} días`;

    return `Vence en ${props.dias} días`;
});

/*
 * El color acompaña a la palabra, no la sustituye: quien no distingue los tonos
 * lee «Vence hoy» igual.
 */
const color = computed(() => {
    if (props.dias === null) return 'var(--color-suave)';
    if (props.dias < 0) return '#dc2626';
    if (props.dias <= 1) return '#d97706';
    if (props.dias <= 3) return '#ca8a04';

    return 'var(--color-suave)';
});

const urgente = computed(() => props.dias !== null && props.dias <= 1);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 whitespace-nowrap text-xs"
        :class="urgente ? 'font-semibold' : ''"
        :style="{ color }"
        :title="fecha ? `Cierra el ${fecha}` : undefined"
    >
        {{ texto }}
        <span v-if="dias !== null && dias < 0 && permiteTarde" class="font-normal opacity-80">
            · todavía se acepta, marcada como tarde
        </span>
    </span>
</template>
