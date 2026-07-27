<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import * as THREE from 'three';
// @ts-expect-error — vanta no trae tipos
import WAVES from 'vanta/dist/vanta.waves.min';
// @ts-expect-error — vanta no trae tipos
import BIRDS from 'vanta/dist/vanta.birds.min';

/**
 * Marco de las pantallas de acceso.
 *
 * En escritorio: el FORMULARIO a la izquierda y a la DERECHA un panel completo
 * con un efecto animado de vanta.js —«Waves» u «Birds», al azar—, con sus
 * colores por defecto (el azul del ejemplo, que sí se aprecia).
 *
 * En móvil: el mismo efecto se vuelve una CABECERA azul arriba, con un borde
 * ondulado que baja hacia el formulario blanco —al estilo de las apps de
 * acceso—, y el formulario debajo.
 *
 * Si vanta o WebGL fallan, el panel queda con su degradado azul y el acceso
 * sigue funcionando.
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
        // Ambos efectos en AZUL: el ejemplo de Waves (0x005588) se ve muy
        // oscuro a escala chica, así que se sube a un azul claro; Birds recibe
        // un fondo azul en vez del navy casi negro de fábrica. Así la cabecera
        // (móvil) y el panel (escritorio) quedan francamente azules.
        efecto = Math.random() < 0.5
            ? BIRDS({ ...base, backgroundColor: 0x1e40af, color1: 0x60a5fa, color2: 0xbfdbfe })
            : WAVES({ ...base, color: 0x1e5fd0, shininess: 55, waveHeight: 18, waveSpeed: 0.85 });
    } catch {
        // Sin WebGL queda el degradado; el acceso no se rompe.
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
    <div class="flex min-h-screen flex-col lg:flex-row">
        <!-- Panel de vanta: cabecera arriba en móvil, columna derecha en escritorio -->
        <div class="panel-vanta relative order-first h-56 w-full overflow-hidden sm:h-64 lg:order-last lg:h-auto lg:w-[52%]">
            <div ref="fondo" class="absolute inset-0"></div>

            <!-- Onda blanca inferior: transición suave hacia el formulario. Solo móvil. -->
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
    </div>
</template>

<style scoped>
.logo {
    background-image: linear-gradient(135deg, #2f6fed, #4f46e5);
}

/* Degradado azul de respaldo: se ve mientras carga vanta o si WebGL falla.
   Mantiene la cabecera «azul» aunque el efecto no aparezca. */
.panel-vanta {
    background-image: linear-gradient(140deg, #1e3a8a 0%, #2563eb 55%, #0ea5e9 100%);
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
