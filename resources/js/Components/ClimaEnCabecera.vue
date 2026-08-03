<script setup lang="ts">
import type { Clima } from '@/utils/clima';

/**
 * El clima, montado dentro de la banda de bienvenida.
 *
 * ── Por qué dejó de ser tarjeta propia ─────────────────────────────────────
 * Estaba en la columna derecha, debajo de la agenda, compitiendo por el sitio
 * con lo que sí reclama trabajo. Y mientras tanto la banda de bienvenida
 * ocupaba el ancho completo de la pantalla para decir un nombre y un saludo:
 * con un solo rol ni siquiera tenía el botón de cambiarlo, y quedaba media
 * pantalla de color liso. El clima cabe ahí sin quitarle nada a nadie.
 *
 * ── El orden de lectura ────────────────────────────────────────────────────
 * Ahora mismo primero —icono y temperatura, que es a lo que se mira—, luego
 * dónde y cómo se siente, y al final los próximos días. Es el orden en que se
 * consulta el clima en cualquier parte.
 *
 * ── Sin fondo propio ───────────────────────────────────────────────────────
 * Un velo blanco translúcido sobre el acento de la escuela, no un color fijo:
 * así se ve igual de bien sobre un tema teal que sobre uno guinda, y el cielo
 * de la banda —estrellas o sol— se sigue viendo a través.
 */
defineProps<{ clima: Clima }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl bg-white/10 px-4 py-3 backdrop-blur-sm">
        <!-- Ahora: el icono y el número, que es a lo que se mira. -->
        <div class="flex items-center gap-3">
            <span class="text-4xl leading-none" aria-hidden="true">{{ clima.icono }}</span>
            <div>
                <p class="text-3xl font-light leading-none">
                    {{ clima.temperatura }}<span class="align-top text-lg">°</span>
                </p>
                <p class="mt-1 text-xs font-medium opacity-90">{{ clima.condicion }}</p>
            </div>
        </div>

        <!-- Dónde y cómo se siente. -->
        <div class="border-white/20 text-xs sm:border-l sm:pl-4">
            <p class="truncate font-medium opacity-90">
                <template v-if="clima.aproximado">Cerca de </template>{{ clima.lugar }}
            </p>
            <p class="mt-0.5 opacity-70">
                Se sienten {{ clima.sensacion }}° · {{ clima.humedad }}% · {{ clima.viento }} km/h
            </p>
            <!--
                La calidad del aire conserva SU color, no el de la banda: es el
                dato con el que se decide si los alumnos salen al patio, y ahí
                el rojo tiene que verse rojo.
            -->
            <p
                v-if="clima.aire"
                class="mt-1 flex items-center gap-1.5"
                :title="clima.aire.recomendacion"
            >
                <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: clima.aire.color }" />
                <span class="font-medium" :style="{ color: clima.aire.color }">
                    Aire: {{ clima.aire.etiqueta }}
                </span>
                <span class="opacity-60">({{ clima.aire.indice }})</span>
            </p>
        </div>

        <!--
            Los próximos días: lo primero que se sacrifica cuando no hay ancho.
            Saber si mañana llueve es útil; saber la temperatura de ahora lo es
            más, y en una pantalla angosta no caben las dos cosas.
        -->
        <div
            v-if="clima.proximos.length"
            class="hidden border-white/20 lg:flex lg:gap-4 lg:border-l lg:pl-4"
        >
            <div v-for="d in clima.proximos" :key="d.fecha" class="text-center">
                <span class="block text-[11px] capitalize opacity-70">{{ d.dia }}</span>
                <span class="my-0.5 block text-base leading-none" :title="d.condicion" aria-hidden="true">
                    {{ d.icono }}
                </span>
                <span class="block text-xs font-medium tabular-nums">
                    {{ d.maxima }}°<span class="ml-0.5 font-normal opacity-60">{{ d.minima }}°</span>
                </span>
                <span
                    v-if="d.lluvia >= 20"
                    class="mt-0.5 block text-[10px] text-sky-200"
                    :title="`${d.lluvia}% de probabilidad de lluvia`"
                >
                    💧{{ d.lluvia }}%
                </span>
            </div>
        </div>

        <span class="ml-auto self-end text-[10px] opacity-50">{{ clima.actualizado }}</span>
    </div>
</template>
