<script setup lang="ts">
/**
 * La banda que corona una ficha: adorno geométrico en vez de color macizo.
 *
 * ── Por qué no un bloque de color ─────────────────────────────────────────
 * Un rectángulo del acento a toda saturación es la mancha más fuerte de la
 * pantalla, y lo que hay debajo —el logo de la escuela, su nombre— es lo que
 * importa. Aros y burbujas tenues ocupan el mismo sitio, dan la misma sensación
 * de cabecera y no compiten con el contenido.
 *
 * ── Cómo respeta el tema sin que haya que pensarlo ────────────────────────
 * Todo sale de `--color-acento` mezclado con `--color-superficie`, así que el
 * adorno es del color de la escuela —teal, guinda, azul marino— y del claro u
 * oscuro que tenga puesto. No hay ni un valor fijo que corregir tema por tema.
 *
 * ── Las figuras van en PÍXELES, no estiradas ──────────────────────────────
 * Un SVG con `viewBox` que cubra la banda entera se escala con ella: la primera
 * versión medía 400×96 y en escritorio, sobre 1200 px de ancho, multiplicaba
 * todo por tres y el juego de aros de arriba terminaba centrado FUERA de la
 * banda. Con `preserveAspectRatio="none"` habría pasado lo otro: aros
 * convertidos en óvalos.
 *
 * Así que cada aro y cada burbuja es un elemento con su diámetro en píxeles y
 * sólo su POSICIÓN en porcentaje. La banda puede medir 400 o 1400 de ancho: un
 * círculo sigue siendo un círculo del mismo tamaño. Es la misma lección que
 * dejó {@see CieloDecorado}, que llegó a producción con las estrellas ovaladas.
 *
 * Las líneas sí van en SVG estirado, porque una recta estirada sigue siendo una
 * recta —y `non-scaling-stroke` mantiene el grosor—.
 */
withDefaults(defineProps<{
    /** Alto de la banda, en clases de Tailwind. */
    alto?: string;
}>(), { alto: 'h-24' });

/*
 * Dos juegos de aros concéntricos, en esquinas opuestas. La opacidad baja
 * conforme se alejan del centro para que el conjunto se desvanezca en vez de
 * terminar en un borde.
 */
const aros = [
    { x: 84, y: 12, d: 108, o: 0.34 },
    { x: 84, y: 12, d: 172, o: 0.22 },
    { x: 84, y: 12, d: 240, o: 0.14 },
    { x: 84, y: 12, d: 312, o: 0.08 },
    { x: 10, y: 96, d: 84, o: 0.24 },
    { x: 10, y: 96, d: 136, o: 0.14 },
    { x: 10, y: 96, d: 196, o: 0.08 },
];

/** Burbujas sueltas: rompen la simetría de los dos juegos de aros. */
const burbujas = [
    { x: 42, y: 30, d: 9, o: 0.22 },
    { x: 58, y: 68, d: 6, o: 0.28 },
    { x: 30, y: 58, d: 5, o: 0.2 },
    { x: 71, y: 22, d: 4, o: 0.24 },
];
</script>

<template>
    <div
        class="relative w-full overflow-hidden"
        :class="alto"
        :style="{
            backgroundColor: 'color-mix(in srgb, var(--color-acento) 8%, var(--color-superficie))',
            color: 'var(--color-acento)',
        }"
        aria-hidden="true"
    >
        <!-- Dos rectas largas y muy tenues: dan dirección al conjunto. -->
        <svg class="absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none">
            <line x1="0" y1="92" x2="100" y2="26" stroke="currentColor" stroke-width="1" stroke-opacity="0.12" vector-effect="non-scaling-stroke" />
            <line x1="0" y1="118" x2="100" y2="52" stroke="currentColor" stroke-width="1" stroke-opacity="0.08" vector-effect="non-scaling-stroke" />
        </svg>

        <!--
            `translate(-50%, -50%)`: la posición es el CENTRO del aro, así que
            los concéntricos comparten punto sin tener que restarle el radio a
            cada uno a mano.
        -->
        <span
            v-for="(a, i) in aros"
            :key="`aro-${i}`"
            class="absolute rounded-full border"
            :style="{
                left: `${a.x}%`,
                top: `${a.y}%`,
                width: `${a.d}px`,
                height: `${a.d}px`,
                marginLeft: `${-a.d / 2}px`,
                marginTop: `${-a.d / 2}px`,
                borderColor: 'currentColor',
                opacity: a.o,
            }"
        />

        <span
            v-for="(b, i) in burbujas"
            :key="`burbuja-${i}`"
            class="absolute rounded-full"
            :style="{
                left: `${b.x}%`,
                top: `${b.y}%`,
                width: `${b.d}px`,
                height: `${b.d}px`,
                marginLeft: `${-b.d / 2}px`,
                marginTop: `${-b.d / 2}px`,
                backgroundColor: 'currentColor',
                opacity: b.o,
            }"
        />
    </div>
</template>
