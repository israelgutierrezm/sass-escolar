<script setup lang="ts">
import type { Clima } from '@/utils/clima';

/**
 * El clima, montado en la banda de bienvenida.
 *
 * ── Por qué dejó de ser tarjeta propia ─────────────────────────────────────
 * Estaba en la columna derecha, debajo de la agenda, compitiendo por el sitio
 * con lo que sí reclama trabajo. Y mientras tanto la banda de bienvenida
 * ocupaba el ancho completo de la pantalla para decir un nombre y un saludo:
 * con un solo rol ni siquiera tenía el botón de cambiarlo, y quedaba media
 * pantalla de color liso. El clima cabe ahí sin quitarle nada a nadie.
 *
 * ── Sin fondo propio ───────────────────────────────────────────────────────
 * El cielo lo pone quien lo monta, no este componente: así el bloque se apoya
 * sobre el mismo degradado que trae las estrellas o el sol, en vez de dibujar
 * un recuadro encima que se notaría como un parche.
 *
 * ── Y sin color propio ─────────────────────────────────────────────────────
 * Estaba escrito en blanco, y con él las separaciones y el subrayado. Eso vale
 * mientras el cielo sea oscuro; al aclarar el de día quedaba texto blanco sobre
 * fondo claro, o sea ilegible. Ahora todo hereda `currentColor` de la franja y
 * las líneas se sacan de él con `color-mix`, así que el mismo bloque se lee
 * sobre cielo claro y sobre cielo de noche.
 *
 * La calidad del aire y la probabilidad de lluvia son las excepciones: llevan
 * SU color porque el dato es el color —un aire malo tiene que verse rojo—.
 *
 * ── El orden de lectura ────────────────────────────────────────────────────
 * Ahora mismo primero —icono y temperatura, que es a lo que se mira—, luego
 * dónde y cómo se siente, y al final los próximos días. Es el orden en que se
 * consulta el clima en cualquier parte.
 */
defineProps<{
    clima: Clima;
    /** Si el navegador puede dar la ubicación. Sin esto no se ofrece nada. */
    puedeUbicar?: boolean;
    ubicando?: boolean;
    /**
     * Sólo para el azul de la lluvia: sobre cielo de noche hace falta uno claro
     * y sobre cielo de día uno oscuro. Todo lo demás sale de `currentColor` y no
     * necesita saber la hora.
     */
    noche?: boolean;
}>();

defineEmits<{ ubicar: [] }>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
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
        <div
            class="min-w-0 text-xs sm:border-l sm:pl-5"
            :style="{ borderColor: 'color-mix(in srgb, currentColor 22%, transparent)' }"
        >
            <p class="truncate font-medium opacity-90">
                <template v-if="clima.aproximado">Cerca de </template>{{ clima.lugar }}
            </p>

            <!--
                Sólo mientras la ubicación sea aproximada: quien ya dio permiso
                —o a quien le sale su propio campus— no tiene nada que arreglar,
                y dejar el botón ahí lo invita a pulsar algo que no hace nada.
            -->
            <button
                v-if="puedeUbicar && clima.aproximado"
                type="button"
                class="mt-0.5 underline underline-offset-2 opacity-70 transition hover:opacity-100 disabled:opacity-50"
                :style="{ textDecorationColor: 'color-mix(in srgb, currentColor 45%, transparent)' }"
                :disabled="ubicando"
                @click="$emit('ubicar')"
            >
                {{ ubicando ? 'Ubicando…' : 'Usar mi ubicación' }}
            </button>
            <p class="mt-0.5 opacity-70">
                Se sienten {{ clima.sensacion }}° · {{ clima.humedad }}% · {{ clima.viento }} km/h
            </p>
            <!--
                La calidad del aire conserva SU color, no el del cielo: es el
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
            class="hidden lg:flex lg:gap-4 lg:border-l lg:pl-5"
            :style="{ borderColor: 'color-mix(in srgb, currentColor 22%, transparent)' }"
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
                    class="mt-0.5 block text-[10px]"
                    :style="{ color: noche ? '#bae6fd' : '#0369a1' }"
                    :title="`${d.lluvia}% de probabilidad de lluvia`"
                >
                    💧{{ d.lluvia }}%
                </span>
            </div>
        </div>
    </div>
</template>
