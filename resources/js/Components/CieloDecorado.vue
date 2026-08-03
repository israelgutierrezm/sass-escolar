<script setup lang="ts">
/**
 * El cielo que decora la banda de bienvenida.
 *
 * ── Por qué encima del color del tema y no en vez de él ────────────────────
 * La banda lleva el acento de la escuela, que es su identidad y no se negocia
 * por adorno. Esto va ENCIMA, en blancos translúcidos: funciona sobre un
 * acento teal, guinda o azul marino sin que haya que pensar en cada tema.
 *
 * ── Y por qué se ve distinto de noche ──────────────────────────────────────
 * Es lo que vuelve la banda de doble uso. Sin esto, la temperatura sería un
 * número más pegado al saludo; con el cielo detrás, la banda dice qué hora es
 * de un vistazo, antes de leer nada.
 */
defineProps<{ noche: boolean }>();

/*
 * Las estrellas se calculan UNA vez y se quedan quietas.
 *
 * Generarlas en el render las haría bailar en cada actualización del
 * componente, que es justo lo que delata que son de adorno. Posiciones fijas
 * por semilla: el mismo cielo mientras la pantalla viva.
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
</script>

<template>
    <!--
        `preserveAspectRatio="none"`: la banda es muy ancha y baja. Con la
        proporción respetada, las estrellas se apelotonaban en el centro y
        dejaban los extremos pelones.
    -->
    <svg
        class="pointer-events-none absolute inset-0 h-full w-full"
        preserveAspectRatio="none"
        viewBox="0 0 100 100"
        aria-hidden="true"
    >
        <template v-if="noche">
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
            <!-- Sol arriba a la derecha y nubes suaves: el mismo lugar donde de
                 noche está la constelación. -->
            <circle cx="88" cy="26" r="16" fill="#fde68a" fill-opacity="0.20" />
            <circle cx="88" cy="26" r="8" fill="#fef3c7" fill-opacity="0.42" />
            <ellipse cx="22" cy="72" rx="24" ry="9" fill="#ffffff" fill-opacity="0.10" />
            <ellipse cx="50" cy="86" rx="32" ry="10" fill="#ffffff" fill-opacity="0.07" />
        </template>
    </svg>
</template>
