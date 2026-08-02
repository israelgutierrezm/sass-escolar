<script setup lang="ts">
import axios from 'axios';
import { onMounted, ref } from 'vue';

/**
 * El clima del campus, en el panel.
 *
 * ── Aparece sola o no aparece ──────────────────────────────────────────────
 * Se pide DESPUÉS de que la página cargó, para que el panel no espere a un
 * servicio de otro país antes de pintar. Si no llega nada —la API se cayó, el
 * campus no tiene coordenadas capturadas— el componente no dibuja nada: una
 * tarjeta con un error de red no le sirve a nadie, y el panel se ve mejor sin
 * ella que con un hueco que dice «no se pudo».
 *
 * ── La calidad del aire no es adorno ───────────────────────────────────────
 * Es el dato con el que una escuela decide si saca a los alumnos al patio, así
 * que se muestra con su recomendación y no sólo con el número: «57» no le dice
 * nada a nadie, «aceptable, quien tenga asma puede resentirlo» sí.
 */
interface Dia {
    fecha: string;
    dia: string;
    maxima: number;
    minima: number;
    lluvia: number;
    icono: string;
    condicion: string;
}

interface Clima {
    temperatura: number;
    sensacion: number;
    humedad: number;
    viento: number;
    es_de_dia: boolean;
    condicion: string;
    icono: string;
    lugar: string;
    aproximado: boolean;
    actualizado: string;
    proximos: Dia[];
    aire: { indice: number; etiqueta: string; color: string; recomendacion: string } | null;
}

const clima = ref<Clima | null>(null);

onMounted(async () => {
    try {
        const { data } = await axios.get('/panel/clima');

        clima.value = data ?? null;
    } catch {
        // Silencio a propósito: es un adorno útil, no información crítica.
        clima.value = null;
    }
});
</script>

<template>
    <section v-if="clima" class="tarjeta overflow-hidden">
        <div class="flex items-start justify-between gap-4 px-5 pt-5">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-contenido">{{ clima.lugar }}</p>
                <p class="text-xs text-suave">
                    <template v-if="clima.aproximado">Ubicación aproximada · </template>
                    {{ clima.condicion }}
                </p>
            </div>
            <span class="shrink-0 text-4xl leading-none" aria-hidden="true">{{ clima.icono }}</span>
        </div>

        <div class="flex items-end gap-3 px-5 pb-3 pt-2">
            <p class="text-5xl font-light leading-none text-contenido">
                {{ clima.temperatura }}<span class="text-2xl align-top">°</span>
            </p>
            <p class="pb-1 text-xs text-suave">
                Se sienten {{ clima.sensacion }}°<br />
                {{ clima.humedad }}% humedad · {{ clima.viento }} km/h
            </p>
        </div>

        <!-- Los próximos días: para planear, no para mirar el cielo ahora. -->
        <ul
            v-if="clima.proximos.length"
            class="flex divide-x divide-borde border-t border-borde"
        >
            <li v-for="d in clima.proximos" :key="d.fecha" class="flex-1 px-2 py-2.5 text-center">
                <span class="block text-[11px] capitalize text-suave">{{ d.dia }}</span>
                <span class="my-0.5 block text-lg leading-none" :title="d.condicion">{{ d.icono }}</span>
                <span class="block text-xs text-contenido">
                    {{ d.maxima }}° <span class="text-suave">{{ d.minima }}°</span>
                </span>
                <span
                    v-if="d.lluvia >= 30"
                    class="mt-0.5 block text-[10px]"
                    :style="{ color: '#2563eb' }"
                    :title="`${d.lluvia}% de probabilidad de lluvia`"
                >
                    💧 {{ d.lluvia }}%
                </span>
            </li>
        </ul>

        <!-- Calidad del aire: el dato que decide si hay actividades al patio. -->
        <div
            v-if="clima.aire"
            class="border-t border-borde px-5 py-3"
            :style="{ backgroundColor: `color-mix(in srgb, ${clima.aire.color} 7%, transparent)` }"
        >
            <p class="flex items-center gap-2 text-xs">
                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: clima.aire.color }" />
                <span class="font-medium" :style="{ color: clima.aire.color }">
                    Aire: {{ clima.aire.etiqueta }}
                </span>
                <span class="text-suave">({{ clima.aire.indice }})</span>
            </p>
            <p class="mt-1 text-xs text-suave">{{ clima.aire.recomendacion }}</p>
        </div>

        <p class="px-5 py-2 text-right text-[10px] text-suave">
            Actualizado {{ clima.actualizado }}
        </p>
    </section>
</template>
