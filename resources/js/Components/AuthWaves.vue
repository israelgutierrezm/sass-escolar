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

// Lo que la plataforma reúne en un solo lugar.
const caracteristicas = [
    { texto: 'Evaluaciones en línea', icono: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z' },
    { texto: 'Calificaciones', icono: 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5' },
    { texto: 'Trámites y documentos', icono: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z' },
    { texto: 'Pagos en línea', icono: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z' },
    { texto: 'Comunicación institucional', icono: 'M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z' },
    { texto: 'Reportes y seguimiento', icono: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z' },
];

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

                <ul class="mt-8 space-y-3.5">
                    <li v-for="c in caracteristicas" :key="c.texto" class="flex items-center gap-3 text-white/95">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white/12 ring-1 ring-white/15">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="c.icono" />
                            </svg>
                        </span>
                        <span class="text-[15px] font-medium">{{ c.texto }}</span>
                    </li>
                </ul>
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
