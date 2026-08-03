<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

/**
 * El clima del campus, con cielo propio.
 *
 * ── Por qué no se parece al resto del panel ────────────────────────────────
 * Las demás tarjetas informan de la escuela y comparten su lenguaje sobrio.
 * Ésta no informa de nada que se pueda gestionar: es la ventana. Darle su
 * propio fondo —de noche o de día, según la hora del CAMPUS— hace dos cosas a
 * la vez: se lee de un golpe sin competir con lo que sí reclama trabajo, y le
 * da al panel un punto de descanso.
 *
 * ── El reparto ─────────────────────────────────────────────────────────────
 * A la izquierda la temperatura, que es a lo que uno mira; a la derecha la
 * condición y la hora, que es lo que se lee después. Abajo, los días. Es el
 * orden en que se consulta el clima en cualquier parte, y pelearse con esa
 * costumbre no aporta nada.
 *
 * ── Aparece sola o no aparece ──────────────────────────────────────────────
 * Se pide DESPUÉS de que la página cargó: el panel no espera a un servicio de
 * otro país para pintar. Si no llega nada, el componente no dibuja: una tarjeta
 * con un error de red no le sirve a nadie.
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
        // Silencio a propósito: es la ventana, no información crítica.
        clima.value = null;
    }
});

/*
 * Las estrellas se calculan UNA vez y se quedan quietas.
 *
 * Generarlas en el render las haría bailar en cada actualización del
 * componente, que es justo lo que delata que son de adorno. Posiciones fijas
 * por semilla: el mismo cielo mientras la tarjeta viva.
 */
const estrellas = Array.from({ length: 46 }, (_, i) => {
    const s = Math.sin(i * 12.9898) * 43758.5453;
    const t = Math.sin(i * 78.233) * 12345.6789;

    return {
        x: Math.abs(s % 100),
        y: Math.abs(t % 100),
        r: (Math.abs(s % 10) / 10) * 1.1 + 0.35,
        o: (Math.abs(t % 10) / 10) * 0.55 + 0.3,
    };
});

/** Une algunas estrellas: una constelación inventada, pero constante. */
const lineas = [
    [2, 7], [7, 13], [13, 19], [19, 24],
    [31, 36], [36, 41], [41, 44],
];

const esDeNoche = computed(() => clima.value !== null && !clima.value.es_de_dia);

/** El cielo: de noche azul profundo; de día, el azul de la mañana. */
const cielo = computed(() =>
    esDeNoche.value
        ? 'linear-gradient(160deg, #0b1220 0%, #131c30 55%, #1b2540 100%)'
        : 'linear-gradient(160deg, #2563eb 0%, #3b82f6 45%, #60a5fa 100%)',
);
</script>

<template>
    <section v-if="clima" class="tarjeta-clima overflow-hidden rounded-2xl" :style="{ background: cielo }">
        <!-- El cielo de fondo: estrellas de noche, sol y nubes de día. -->
        <div class="relative">
            <svg class="pointer-events-none absolute inset-0 h-full w-full" preserveAspectRatio="none" viewBox="0 0 100 100" aria-hidden="true">
                <template v-if="esDeNoche">
                    <line
                        v-for="([a, b], i) in lineas"
                        :key="`l-${i}`"
                        :x1="estrellas[a].x" :y1="estrellas[a].y"
                        :x2="estrellas[b].x" :y2="estrellas[b].y"
                        stroke="#ffffff" stroke-width="0.15" stroke-opacity="0.22"
                    />
                    <circle
                        v-for="(e, i) in estrellas"
                        :key="`e-${i}`"
                        :cx="e.x" :cy="e.y" :r="e.r * 0.35"
                        fill="#ffffff" :fill-opacity="e.o"
                    />
                </template>

                <template v-else>
                    <!-- Sol arriba a la derecha y nubes suaves: el mismo lugar
                         donde de noche está la constelación. -->
                    <circle cx="84" cy="22" r="13" fill="#fde68a" fill-opacity="0.35" />
                    <circle cx="84" cy="22" r="7" fill="#fef3c7" fill-opacity="0.7" />
                    <ellipse cx="26" cy="70" rx="22" ry="7" fill="#ffffff" fill-opacity="0.12" />
                    <ellipse cx="52" cy="82" rx="30" ry="8" fill="#ffffff" fill-opacity="0.09" />
                </template>
            </svg>

            <!-- Izquierda: dónde y cuánto. Derecha: qué y cuándo. -->
            <div class="relative flex items-start justify-between gap-4 px-5 pb-4 pt-5">
                <div class="min-w-0">
                    <p class="truncate text-[11px] font-medium uppercase tracking-[0.12em] text-white/70">
                        {{ clima.lugar }}
                    </p>
                    <p class="mt-1 text-5xl font-light leading-none text-white">
                        {{ clima.temperatura }}<span class="align-top text-2xl">°</span>
                    </p>
                    <p class="mt-1.5 text-xs text-white/70">
                        Se sienten {{ clima.sensacion }}° · {{ clima.humedad }}% · {{ clima.viento }} km/h
                    </p>
                </div>

                <div class="shrink-0 text-right">
                    <span class="block text-3xl leading-none" aria-hidden="true">{{ clima.icono }}</span>
                    <p class="mt-1.5 text-sm font-medium text-white">{{ clima.condicion }}</p>
                    <p class="text-[11px] text-white/60">
                        <template v-if="clima.aproximado">Aproximado · </template>{{ clima.actualizado }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Los próximos días, sobre un velo para separarlos del cielo. -->
        <div v-if="clima.proximos.length" class="flex divide-x divide-white/10 bg-black/20">
            <div v-for="d in clima.proximos" :key="d.fecha" class="flex-1 px-1 py-2.5 text-center">
                <span class="block text-[11px] capitalize text-white/60">{{ d.dia }}</span>
                <span class="my-0.5 block text-base leading-none" :title="d.condicion">{{ d.icono }}</span>
                <span class="block text-xs font-medium text-white">
                    {{ d.maxima }}° <span class="font-normal text-white/50">{{ d.minima }}°</span>
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

        <!--
            La calidad del aire conserva SU color, no el del cielo: es el dato
            con el que se decide si los alumnos salen al patio, y ahí el rojo
            tiene que verse rojo.
        -->
        <div v-if="clima.aire" class="bg-black/30 px-5 py-3">
            <p class="flex items-center gap-2 text-xs">
                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: clima.aire.color }" />
                <span class="font-medium" :style="{ color: clima.aire.color }">
                    Aire: {{ clima.aire.etiqueta }}
                </span>
                <span class="text-white/50">({{ clima.aire.indice }})</span>
            </p>
            <p class="mt-1 text-xs text-white/60">{{ clima.aire.recomendacion }}</p>
        </div>
    </section>
</template>

<style scoped>
/* Sombra propia: la tarjeta tiene fondo oscuro y la del tema no se ve sobre él. */
.tarjeta-clima {
    box-shadow:
        0 1px 2px rgb(0 0 0 / 0.08),
        0 8px 24px -12px rgb(0 0 0 / 0.45);
}
</style>
