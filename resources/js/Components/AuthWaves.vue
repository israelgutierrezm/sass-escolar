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

// Frase educativa al azar sobre el panel derecho (una por carga).
const FRASES = [
    'Aprender hoy, transformar el mañana.',
    'El conocimiento es el mejor patrimonio.',
    'Cada clase, un paso hacia tu futuro.',
    'Formar personas, construir país.',
    'La educación abre todas las puertas.',
    'Enseñar es sembrar el porvenir.',
    'Estudiar hoy, liderar mañana.',
    'El saber transforma vidas.',
];
const frase = FRASES[Math.floor(Math.random() * FRASES.length)];

const fondo = ref<HTMLElement | null>(null);
let efecto: { destroy: () => void; resize: () => void } | null = null;
let observador: ResizeObserver | null = null;

onMounted(() => {
    if (fondo.value === null) {
        return;
    }

    // En MÓVIL no se produce nada: sin vanta, sin lienzo WebGL. La pantalla es
    // solo el formulario con el logo. El panel animado es de escritorio.
    if (!window.matchMedia('(min-width: 1024px)').matches) {
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
        <!-- Panel de vanta: SOLO escritorio (columna derecha, alto completo).
             En móvil no se muestra: la pantalla queda solo con el formulario. -->
        <div class="panel-vanta relative hidden overflow-hidden lg:order-last lg:block lg:h-screen lg:w-[52%]">
            <div ref="fondo" class="absolute inset-0"></div>

            <!-- Velo tenue para que el texto blanco se lea sobre el efecto. -->
            <div class="absolute inset-0" style="background: linear-gradient(115deg, rgba(10,20,32,.55), rgba(10,20,32,.15) 55%, transparent)"></div>

            <!-- Overlay: frase educativa al azar + lo que reúne la plataforma. -->
            <div class="relative z-10 flex h-full flex-col justify-center px-12 text-white xl:px-16">
                <svg class="mb-3 h-11 w-11 text-white/45" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M7.5 5.25A3.75 3.75 0 0 0 3.75 9v6a.75.75 0 0 0 .75.75H9a.75.75 0 0 0 .75-.75V9A.75.75 0 0 0 9 8.25H6a2.25 2.25 0 0 1 2.25-2.25.75.75 0 0 0 0-1.5H7.5Zm9 0A3.75 3.75 0 0 0 12.75 9v6a.75.75 0 0 0 .75.75h4.5a.75.75 0 0 0 .75-.75V9A.75.75 0 0 0 18 8.25h-3a2.25 2.25 0 0 1 2.25-2.25.75.75 0 0 0 0-1.5h-.75Z" />
                </svg>

                <h2 class="text-3xl font-bold leading-tight tracking-tight drop-shadow-sm xl:text-4xl">{{ frase }}</h2>
                <span class="mt-5 block h-1 w-14 rounded-full bg-white/70"></span>

                <p class="mt-6 max-w-sm text-base text-white/85">
                    Todo lo que necesitas para tu formación académica en un solo lugar.
                </p>
            </div>
        </div>

        <!-- Panel del formulario -->
        <div class="order-2 flex flex-1 items-center justify-center bg-white px-6 py-8 lg:order-first lg:py-10">
            <div class="entra w-full max-w-sm">
                <div class="mb-8 flex flex-col items-center text-center">
                    <!-- El logo de la institución va centrado y grande, sin marco.
                         Sin logo, cae al ícono genérico. -->
                    <img
                        v-if="institucion?.logo"
                        :src="institucion.logo"
                        :alt="institucion.nombre"
                        class="h-28 w-auto max-w-[16rem] object-contain sm:h-32"
                    />
                    <span v-else class="logo grid h-20 w-20 place-items-center rounded-2xl text-white shadow-lg">
                        <svg class="h-11 w-11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
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

                <!-- Aviso de privacidad y soporte. Destinos por definir. -->
                <div class="mt-9 text-center text-xs text-slate-400">
                    <a href="#aviso-de-privacidad" class="transition hover:text-slate-600">Aviso de privacidad</a>
                    <span class="mx-2 text-slate-300">•</span>
                    <a href="mailto:soporte@acadion.mx" class="transition hover:text-slate-600">Soporte</a>
                </div>
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
