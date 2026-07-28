<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import * as THREE from 'three';
// @ts-expect-error — vanta no trae tipos
import WAVES from 'vanta/dist/vanta.waves.min';
// @ts-expect-error — vanta no trae tipos
import BIRDS from 'vanta/dist/vanta.birds.min';

/**
 * Marco de las pantallas de acceso.
 *
 * Escritorio: FORMULARIO a la izquierda; a la DERECHA el panel completo con un
 * efecto animado de vanta.js —«Waves» u «Birds», al azar— con SUS COLORES POR
 * DEFECTO (los del demo de vanta).
 *
 * Móvil: el mismo efecto se vuelve una CABECERA arriba con una onda blanca que
 * baja hacia el formulario blanco.
 *
 * El panel lleva altura explícita (`lg:h-screen`) y, tras montar, se fuerza un
 * `resize()` del efecto: si vanta arrancara antes de que el panel tuviera su
 * tamaño final —lo que hacía que en escritorio no se viera nada—, el reajuste
 * lo corrige. Si WebGL falla, queda el fondo neutro y el acceso no se rompe.
 */
// La institución (nombre + logo) para membretar el acceso, si ya está cargada.
const institucion = computed(() => (usePage().props.escuela as any)?.institucion ?? null);

const fondo = ref<HTMLElement | null>(null);
let efecto: { destroy: () => void; resize: () => void } | null = null;
let observador: ResizeObserver | null = null;

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
        // Colores por defecto (los del demo de vanta): sin overrides.
        efecto = Math.random() < 0.5 ? BIRDS(base) : WAVES(base);
    } catch {
        return; // sin WebGL queda el fondo neutro
    }

    // Reajuste tras el layout: en escritorio el panel toma su alto por flex y
    // vanta podía quedar montado con un tamaño equivocado.
    const reajustar = () => {
        try {
            efecto?.resize();
        } catch {
            // nada
        }
    };

    requestAnimationFrame(reajustar);
    setTimeout(reajustar, 300);

    if (typeof ResizeObserver !== 'undefined') {
        observador = new ResizeObserver(reajustar);
        observador.observe(fondo.value);
    }
});

onBeforeUnmount(() => {
    observador?.disconnect();

    try {
        efecto?.destroy();
    } catch {
        // nada
    }
});
</script>

<template>
    <div class="flex min-h-screen flex-col lg:flex-row">
        <!-- Panel de vanta: cabecera arriba en móvil, columna derecha (alto
             completo) en escritorio. -->
        <div class="panel-vanta relative order-first h-56 w-full overflow-hidden sm:h-64 lg:order-last lg:h-screen lg:w-[52%]">
            <div ref="fondo" class="absolute inset-0"></div>

            <!-- Onda blanca inferior: transición hacia el formulario. Solo móvil. -->
            <svg
                class="absolute inset-x-0 -bottom-px h-12 w-full lg:hidden"
                viewBox="0 0 1440 120"
                preserveAspectRatio="none"
                fill="#ffffff"
                aria-hidden="true"
            >
                <path d="M0,70 C240,120 480,20 720,55 C960,90 1200,25 1440,65 L1440,120 L0,120 Z" />
            </svg>
        </div>

        <!-- Panel del formulario -->
        <div class="order-2 flex flex-1 items-center justify-center bg-white px-6 py-8 lg:order-first lg:py-10">
            <div class="entra w-full max-w-sm">
                <div class="mb-8 flex flex-col items-center text-center lg:items-start lg:text-left">
                    <!-- Una vez cargado, el logo de la institución reemplaza la
                         marca genérica. Sin logo, cae al ícono por defecto. -->
                    <img
                        v-if="institucion?.logo"
                        :src="institucion.logo"
                        :alt="institucion.nombre"
                        class="h-16 w-16 rounded-2xl bg-white object-contain shadow-lg ring-1 ring-slate-200"
                    />
                    <span v-else class="logo grid h-14 w-14 place-items-center rounded-2xl text-white shadow-lg">
                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.42A12 12 0 0 1 12 21a12 12 0 0 1-6.16-10.42L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 9v5" />
                        </svg>
                    </span>
                    <h1 class="mt-4 text-2xl font-bold tracking-tight text-slate-800">
                        {{ institucion?.nombre ?? 'Acadion' }}
                    </h1>
                    <slot name="subtitulo" />
                </div>

                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
.logo {
    background-image: linear-gradient(135deg, #2f6fed, #4f46e5);
}

/* Fondo neutro oscuro mientras vanta pinta (o si WebGL falla): no compite con
   los colores del efecto ni impone un azul. */
.panel-vanta {
    background-color: #0a1420;
}

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

@media (prefers-reduced-motion: reduce) {
    .entra {
        animation: none;
    }
}
</style>
