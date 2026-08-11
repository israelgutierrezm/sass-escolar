<script setup lang="ts">
/**
 * El cielo que decora la banda de bienvenida.
 *
 * ── Por qué no es un SVG estirado ──────────────────────────────────────────
 * Lo era, con `preserveAspectRatio="none"` sobre un viewBox cuadrado. La banda
 * mide casi 1000×140, así que el dibujo se escalaba siete veces más a lo ancho
 * que a lo alto: las estrellas salían ovaladas y el sol era una mancha
 * horizontal. Se veía estirado porque lo estaba.
 *
 * Ahora cada figura se dibuja en CSS con su tamaño en píxeles y sólo su
 * POSICIÓN va en porcentaje. Da igual el alto que tenga la banda: un círculo
 * sigue siendo un círculo.
 *
 * ── Sobre el color del tema, no en su lugar ────────────────────────────────
 * Todo va en `currentColor` translúcido: hereda el color del texto de la franja
 * que lo contiene. Estaba escrito en blanco fijo, y eso sólo servía mientras el
 * cielo fuera oscuro; al aclarar el de día las estrellas y las nubes habrían
 * desaparecido sobre su propio fondo. Heredando, el mismo dibujo funciona en
 * cielo claro y en cielo de noche, y con cualquier acento.
 *
 * El sol es la excepción y va en ámbar: un sol del color del texto no es un
 * sol.
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
        d: (Math.abs(s % 10) / 10) * 1.8 + 1.2,
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
    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
        <template v-if="noche">
            <!--
                Las líneas sí van en SVG —una recta estirada sigue siendo una
                recta—, pero con `non-scaling-stroke` para que el grosor no
                cambie con el ángulo. Las coordenadas son porcentajes, las
                mismas que usan las estrellas, así que unen lo que deben.
            -->
            <svg class="absolute inset-0 h-full w-full" preserveAspectRatio="none" viewBox="0 0 100 100">
                <line
                    v-for="([a, b], i) in lineas"
                    :key="`l-${i}`"
                    :x1="estrellas[a].x" :y1="estrellas[a].y"
                    :x2="estrellas[b].x" :y2="estrellas[b].y"
                    stroke="currentColor" stroke-width="1" stroke-opacity="0.2"
                    vector-effect="non-scaling-stroke"
                />
            </svg>

            <span
                v-for="(e, i) in estrellas"
                :key="`e-${i}`"
                class="absolute rounded-full"
                :style="{
                    backgroundColor: 'currentColor',
                    left: `${e.x}%`,
                    top: `${e.y}%`,
                    width: `${e.d}px`,
                    height: `${e.d}px`,
                    opacity: e.o,
                }"
            />
        </template>

        <template v-else>
            <!-- El sol, arriba a la derecha: el mismo sitio donde de noche está
                 la constelación. Un halo suave, no un disco recortado. -->
            <span class="sol absolute" />
            <span class="nube nube-a absolute" />
            <span class="nube nube-b absolute" />
        </template>
    </div>
</template>

<style scoped>
.sol {
    top: -28px;
    right: 8%;
    width: 132px;
    height: 132px;
    border-radius: 9999px;
    background: radial-gradient(
        circle,
        rgb(253 224 71 / 0.6) 0%,
        rgb(250 204 21 / 0.3) 42%,
        transparent 70%
    );
}

/*
 * Nubes: elipses muy tenues y desenfocadas, para que sugieran sin dibujar.
 *
 * Blancas, y NO en `currentColor` como las estrellas: una nube es un claro
 * sobre el cielo, no una mancha del color del texto. Con el cielo de día
 * aclarado, en `currentColor` habrían salido grises —sombras, no nubes—.
 */
.nube {
    border-radius: 9999px;
    background: rgb(255 255 255 / 0.5);
    filter: blur(10px);
}

.nube-a {
    bottom: -14px;
    left: 12%;
    width: 190px;
    height: 44px;
}

.nube-b {
    bottom: -22px;
    left: 46%;
    width: 240px;
    height: 50px;
}
</style>
