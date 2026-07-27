<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import * as THREE from 'three';
// @ts-expect-error — vanta no trae tipos
import WAVES from 'vanta/dist/vanta.waves.min';
// @ts-expect-error — vanta no trae tipos
import BIRDS from 'vanta/dist/vanta.birds.min';

/**
 * Marco de las pantallas de acceso: el FORMULARIO a la izquierda y, a la
 * derecha, un CÍRCULO con un efecto animado de vanta.js adentro —«Waves» u
 * «Birds», elegido al azar en cada visita—. El efecto se recorta al círculo,
 * así queda contenido y no invade la lectura del formulario.
 *
 * Se usan los COLORES POR DEFECTO de vanta (el azul propio de Waves y la
 * parvada de Birds), que sí se aprecian; un color forzado dejaba el agua
 * invisible. Si vanta o WebGL fallan, el círculo queda con su degradado y el
 * acceso sigue funcionando.
 *
 * En pantallas chicas el panel del círculo se oculta y el formulario ocupa todo
 * el ancho.
 */
const fondo = ref<HTMLElement | null>(null);
let efecto: { destroy: () => void } | null = null;

onMounted(() => {
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

    try {
        // Colores por defecto de cada efecto: nada de overrides de color.
        efecto = Math.random() < 0.5 ? BIRDS(base) : WAVES(base);
    } catch {
        // Sin WebGL el círculo se queda con su degradado; el acceso no se rompe.
    }
});

onBeforeUnmount(() => {
    try {
        efecto?.destroy();
    } catch {
        // nada
    }
});
</script>

<template>
    <div class="flex min-h-screen w-full">
        <!-- IZQUIERDA: formulario -->
        <div class="flex w-full items-center justify-center bg-white px-6 py-10 lg:w-[46%]">
            <div class="entra w-full max-w-sm">
                <div class="mb-8 flex flex-col items-center text-center lg:items-start lg:text-left">
                    <span class="logo grid h-14 w-14 place-items-center rounded-2xl text-white shadow-lg">
                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42A12 12 0 0 1 12 21a12 12 0 0 1-6.16-10.42L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 9v5" />
                        </svg>
                    </span>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight text-slate-800">Acadion</h1>
                    <slot name="subtitulo" />
                </div>

                <slot />
            </div>
        </div>

        <!-- DERECHA: círculo con el efecto de vanta -->
        <div class="panel-derecho relative hidden w-[54%] items-center justify-center overflow-hidden lg:flex">
            <!-- Anillos decorativos detrás del círculo -->
            <div class="anillo absolute aspect-square w-[92%] max-w-[680px] rounded-full border border-white/40"></div>
            <div class="anillo anillo-2 absolute aspect-square w-[78%] max-w-[580px] rounded-full border border-white/30"></div>

            <!-- El círculo con vanta adentro -->
            <div class="flota relative aspect-square w-[70%] max-w-[520px]">
                <div class="absolute inset-0 overflow-hidden rounded-full shadow-2xl ring-8 ring-white/40">
                    <div ref="fondo" class="h-full w-full" style="background: linear-gradient(135deg, #0b3d66, #0a6e8f)"></div>
                </div>
                <!-- Brillo sobre el círculo para darle volumen -->
                <div class="pointer-events-none absolute inset-0 rounded-full" style="background: radial-gradient(60% 50% at 35% 25%, rgba(255,255,255,0.35), transparent 60%)"></div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.logo {
    background-image: linear-gradient(135deg, #2f6fed, #4f46e5);
}

.panel-derecho {
    background-image: linear-gradient(135deg, #eaf1ff 0%, #dfe7ff 55%, #eef2ff 100%);
}

/* Entrada del formulario. */
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

/* El círculo flota con calma. */
@keyframes flotar {
    0%,
    100% {
        transform: translateY(0) scale(1);
    }
    50% {
        transform: translateY(-12px) scale(1.015);
    }
}

.flota {
    animation: flotar 7s ease-in-out infinite;
}

/* Los anillos giran despacio en sentidos opuestos. */
@keyframes girar {
    to {
        transform: rotate(360deg);
    }
}

.anillo {
    animation: girar 60s linear infinite;
}

.anillo-2 {
    animation-direction: reverse;
    animation-duration: 45s;
}

@media (prefers-reduced-motion: reduce) {
    .entra,
    .flota,
    .anillo {
        animation: none;
    }
}
</style>
