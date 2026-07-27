<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import * as THREE from 'three';
// @ts-expect-error — vanta no trae tipos
import WAVES from 'vanta/dist/vanta.waves.min';

/**
 * Marco de las pantallas de acceso (login, recuperación): un fondo animado de
 * «olas» (efecto Waves de vanta.js) con una figura y el contenido centrado
 * encima en una tarjeta translúcida.
 *
 * El efecto es puramente decorativo: si vanta o WebGL fallan, se descarta el
 * error y queda el fondo sólido, sin romper el acceso.
 */
const fondo = ref<HTMLElement | null>(null);
let efecto: { destroy: () => void } | null = null;

onMounted(() => {
    if (fondo.value === null) {
        return;
    }

    try {
        efecto = WAVES({
            el: fondo.value,
            THREE,
            mouseControls: true,
            touchControls: true,
            gyroControls: false,
            minHeight: 200,
            minWidth: 200,
            scale: 1,
            scaleMobile: 1,
            color: 0x0f2747,
            shininess: 40,
            waveHeight: 16,
            waveSpeed: 0.75,
            zoom: 0.92,
        });
    } catch {
        // Sin WebGL el fondo se queda sólido; el acceso sigue funcionando.
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
    <div class="relative min-h-screen w-full overflow-hidden" style="background-color: #0f2747">
        <!-- Lienzo del efecto Waves -->
        <div ref="fondo" class="absolute inset-0"></div>

        <!-- Contenido -->
        <div class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10">
            <div class="w-full max-w-md">
                <div class="mb-6 flex flex-col items-center text-center">
                    <!-- Figura: birrete académico -->
                    <svg class="h-14 w-14 text-white drop-shadow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42A12 12 0 0 1 12 21a12 12 0 0 1-6.16-10.42L12 14z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 9v5" />
                    </svg>
                    <h1 class="mt-3 text-3xl font-bold text-white drop-shadow">Acadion</h1>
                    <slot name="subtitulo" />
                </div>

                <div class="rounded-2xl border border-white/20 bg-white/95 p-8 shadow-2xl backdrop-blur">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
