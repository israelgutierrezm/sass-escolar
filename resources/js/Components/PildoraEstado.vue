<script setup lang="ts">
import { computed } from 'vue';

/**
 * Píldora de estado con punto de color: el patrón ESTÁNDAR para mostrar el
 * estatus/situación de un registro en tablas y en la vista cuadrícula.
 *
 * El color lo decide el PROPIO ESTADO, no cada pantalla. Antes cada listado
 * llevaba su mapa —`colorEstatus`, `colorSituacion`…— y el mismo «activo» salía
 * verde en alumnos y gris en grupos, porque ahí nadie se acordó de pasar el
 * color. Un semáforo que cambia de significado según la pantalla no es un
 * semáforo. Aquí vive una sola vez: verde lo que está en marcha, rojo lo que se
 * cortó, ámbar lo que está a medias, gris lo que todavía no empieza.
 *
 * `color` sigue existiendo para un estado que este vocabulario no cubra.
 */
const props = withDefaults(
    defineProps<{
        texto?: string | null;
        /** Color sólido explícito; gana sobre el vocabulario de abajo. */
        color?: string;
        /** Sin mayúscula inicial automática (para textos ya formateados). */
        sinCapitalizar?: boolean;
    }>(),
    { sinCapitalizar: false },
);

const VERDE = '#16a34a';
const ROJO = '#dc2626';
const AMBAR = '#d97706';
const NEUTRO = 'var(--color-suave)';

/**
 * Vocabulario de estados del sistema. La llave es el texto normalizado (sin
 * acentos, en minúsculas), porque el mismo estado llega unas veces como clave
 * («por_renovar») y otras como nombre ya formateado («Por renovar»).
 */
const COLORES: Record<string, string> = {
    // En marcha
    activo: VERDE, activa: VERDE, abierto: VERDE, abierta: VERDE,
    inscrito: VERDE, cursando: VERDE, vigente: VERDE, pagado: VERDE,
    aprobada: VERDE, aprobado: VERDE, firmado: VERDE, timbrada: VERDE,
    aceptado: VERDE, aceptada: VERDE, autorizado: VERDE, autorizada: VERDE,
    pagado: VERDE, pagada: VERDE, completado: VERDE, completada: VERDE,
    // Falló: algo salió mal y hay que hacer algo al respecto
    baja: ROJO, perdida: ROJO, rechazado: ROJO, rechazada: ROJO,
    reprobada: ROJO, reprobado: ROJO, vencido: ROJO, vencida: ROJO,
    moroso: ROJO, error: ROJO, fallido: ROJO, fallida: ROJO,
    // A medias: pide atención pero no es una falla
    pendiente: AMBAR, suspendida: AMBAR, suspendido: AMBAR,
    parcial: AMBAR, 'por renovar': AMBAR, por_renovar: AMBAR,
    'en espera': AMBAR, en_espera: AMBAR, revision: AMBAR,
    timbrando: AMBAR, enviando: AMBAR, procesando: AMBAR,
    devengada: AMBAR, devengado: AMBAR,
    // Cerrado con final normal: informa, no alerta
    egresado: 'var(--color-acento)', egresada: 'var(--color-acento)',
    titulado: 'var(--color-acento)', titulada: 'var(--color-acento)',
    concluido: 'var(--color-acento)', concluida: 'var(--color-acento)',
    condonado: 'var(--color-acento)', condonada: 'var(--color-acento)',
    reembolsado: 'var(--color-acento)', reembolsada: 'var(--color-acento)',
    /*
     * Todavía no empieza, o se cerró a propósito.
     *
     * «Cancelado» va aquí y no en rojo: cancelar es una decisión deliberada, no
     * una falla. Salía en gris en el estado de cuenta y en violeta en facturas,
     * dos pantallas que muestran la MISMA factura cancelada. El rojo se reserva
     * para lo que de verdad se rompió (error de timbrado, pago fallido).
     */
    borrador: NEUTRO, inactivo: NEUTRO, inactiva: NEUTRO,
    cerrado: NEUTRO, cerrada: NEUTRO, cancelado: NEUTRO, cancelada: NEUTRO,
};

const normaliza = (t: string) =>
    t.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

const colorFinal = computed(
    () => props.color ?? COLORES[normaliza(props.texto ?? '')] ?? NEUTRO,
);
</script>

<template>
    <span
        v-if="texto"
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
        :class="sinCapitalizar ? '' : 'capitalize'"
        :style="{ color: colorFinal, backgroundColor: `color-mix(in srgb, ${colorFinal} 14%, transparent)` }"
    >
        <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: colorFinal }" />
        {{ texto }}
    </span>
</template>
