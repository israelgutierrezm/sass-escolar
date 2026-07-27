<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import * as THREE from 'three';
// @ts-expect-error — vanta no trae tipos
import WAVES from 'vanta/dist/vanta.waves.min';
// @ts-expect-error — vanta no trae tipos
import BIRDS from 'vanta/dist/vanta.birds.min';

/**
 * Marco de las pantallas de acceso (login, recuperación): un fondo animado que
 * alterna AL AZAR entre dos efectos de vanta.js —«Waves» (olas) y «Birds»
 * (parvada)—, con la figura y la tarjeta translúcida encima. Cada visita puede
 * traer uno u otro, para que la entrada no se sienta siempre igual.
 *
 * El efecto es decorativo: si vanta o WebGL fallan, se descarta el error y
 * queda el fondo sólido, sin romper el acceso. Los dos efectos comparten el
 * mismo azul marino de fondo para que la tarjeta se vea igual caiga cual caiga.
 */
const FONDO = 0x0b1f3a;

const fondo = ref<HTMLElement | null>(null);
const efectoActivo = ref<'olas' | 'parvada'>('olas');
let efecto: { destroy: () => void } | null = null;

function iniciar(): void {
    if (fondo.value === null) {
        return;
    }

    const base = {
        el: fondo.value,
        THREE,
        mouseControls: true,
        touchControls: true,
        gyroControls: false,
        minHeight: 200,
        minWidth: 200,
        scale: 1,
        scaleMobile: 1,
    };

    const usarParvada = Math.random() < 0.5;
    efectoActivo.value = usarParvada ? 'parvada' : 'olas';

    try {
        efecto = usarParvada
            ? BIRDS({
                ...base,
                backgroundColor: FONDO,
                color1: 0x2f6fed,
                color2: 0x39c0f0,
                birdSize: 1.2,
                wingSpan: 26,
                speedLimit: 4,
                separation: 45,
                alignment: 28,
                cohesion: 28,
                quantity: 3,
            })
            : WAVES({
                ...base,
                color: 0x102a4c,
                shininess: 45,
                waveHeight: 17,
                waveSpeed: 0.8,
                zoom: 0.9,
            });
    } catch {
        // Sin WebGL el fondo se queda sólido; el acceso sigue funcionando.
    }
}

onMounted(iniciar);

onBeforeUnmount(() => {
    try {
        efecto?.destroy();
    } catch {
        // nada
    }
});
</script>

<template>
    <div class="marco relative min-h-screen w-full overflow-hidden" :style="{ backgroundColor: '#0b1f3a' }">
        <!-- Lienzo del efecto de vanta -->
        <div ref="fondo" class="absolute inset-0"></div>

        <!-- Velo con degradado para dar profundidad y que el texto contraste. -->
        <div class="velo pointer-events-none absolute inset-0"></div>

        <!-- Contenido -->
        <div class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10">
            <div class="w-full max-w-md">
                <div class="mb-6 flex flex-col items-center text-center">
                    <span class="entra logo grid h-16 w-16 place-items-center rounded-2xl bg-white/10 ring-1 ring-white/20 backdrop-blur">
                        <svg class="flota h-9 w-9 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42A12 12 0 0 1 12 21a12 12 0 0 1-6.16-10.42L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 9v5" />
                        </svg>
                    </span>
                    <h1 class="entra d1 mt-3 text-3xl font-bold tracking-tight text-white drop-shadow">Acadion</h1>
                    <div class="entra d2">
                        <slot name="subtitulo" />
                    </div>
                </div>

                <div class="entra d3 tarjeta-acceso rounded-2xl border border-white/20 bg-white/95 p-8 shadow-2xl backdrop-blur-xl">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Velo: oscurece las orillas y añade un tinte para que el fondo animado no
   compita con la tarjeta ni con el texto. */
.velo {
    background:
        radial-gradient(120% 90% at 50% 0%, transparent 40%, rgba(4, 12, 26, 0.55) 100%),
        linear-gradient(180deg, rgba(4, 12, 26, 0.15), rgba(4, 12, 26, 0.45));
}

/* Entrada escalonada: el logo, el título y la tarjeta suben y aparecen en
   secuencia. Sobrio, no aparatoso. */
@keyframes entrar {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.entra {
    animation: entrar 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.d1 {
    animation-delay: 0.08s;
}

.d2 {
    animation-delay: 0.16s;
}

.d3 {
    animation-delay: 0.24s;
}

/* Flotar: el birrete sube y baja apenas, con calma. */
@keyframes flotar {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-4px);
    }
}

.flota {
    animation: flotar 4s ease-in-out infinite;
}

.tarjeta-acceso {
    box-shadow:
        0 24px 60px -20px rgba(0, 0, 0, 0.55),
        0 0 0 1px rgba(255, 255, 255, 0.06);
}

@media (prefers-reduced-motion: reduce) {
    .entra,
    .flota {
        animation: none;
    }
}
</style>
