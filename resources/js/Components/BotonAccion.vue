<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { ACCIONES, type VarianteAccion } from '@/utils/acciones';

/**
 * Botón de acción con icono y color propio por tipo.
 *
 * Unifica cómo se ven «Nuevo», «Editar», «Eliminar», «Ver» y «Cerrar» en toda
 * la app: se repetían como texto suelto, cada listado con su propio tono, y era
 * imposible reconocer una acción de un vistazo. Aquí cada variante trae su icono
 * y un color discreto pero particular; «Nuevo» va relleno (es la acción
 * principal), el resto son botones fantasma con un fondo tenue de su color; al
 * pasar el cursor solo se anima el icono.
 *
 * «editar», «eliminar» y «cerrar» son SIEMPRE solo-icono (sin texto, solo su
 * tooltip). `solo-icono` fuerza ese modo también en otras variantes. Las
 * acciones poco evidentes (p. ej. «Malla», «Captura») se dejan con texto.
 *
 * Con `href` se renderiza como enlace de Inertia; sin él, como `<button>` que
 * emite `click`.
 */
const props = withDefaults(
    defineProps<{
        variante: VarianteAccion;
        href?: string;
        texto?: string;
        soloIcono?: boolean;
        disabled?: boolean;
        /** Texto en peso normal (no medium): para botones discretos, p. ej. el
         *  «Agregar» de los formularios pequeños de catálogo. */
        fino?: boolean;
        /**
         * Icono al final en vez de al principio. Para el botón que abre algo y
         * se convierte en su propio cierre: la etiqueta se queda quieta —dice
         * QUÉ se abrió— y el icono pasa a la derecha, donde se lee como
         * «cerrar esto» y no como parte del nombre.
         */
        iconoAlFinal?: boolean;
        /**
         * Redondo: un círculo con sólo el icono y la leyenda en el `title`.
         *
         * Es la forma de las acciones dentro de una TARJETA de cuadrícula. Ahí
         * conviven tres o cuatro botones en un ancho de 270 px, y con etiquetas
         * cada tarjeta acababa con un pie distinto —«Inscribir  Materias  ✎  🗑»
         * en una, dos iconos en la siguiente—, que es justo lo que hacía que
         * unas cuadrículas se vieran de otra familia que otras. Redondos y sin
         * texto todos ocupan lo mismo y el pie de todas las tarjetas del sistema
         * se lee igual; lo que hacen sigue estando, a un cursor de distancia.
         */
        redondo?: boolean;
    }>(),
    { soloIcono: false, disabled: false, fino: false, iconoAlFinal: false, redondo: false },
);

const emit = defineEmits<{ click: [] }>();

/*
 * La etiqueta, el color y el icono de cada variante viven en `@/utils/acciones`
 * desde que hay un segundo consumidor: `MenuAcciones.vue`, el menu de tres
 * puntos de los listados. Con la tabla copiada, el «Eliminar» del menu acabaria
 * siendo de otro rojo que el de este boton.
 */
const cfg = computed(() => ACCIONES[props.variante]);
const etiqueta = computed(() => props.texto ?? cfg.value.etiqueta);
const esPrimario = computed(() => props.variante === 'nuevo');

// Regla homologada en TODO el sistema: «editar» y «eliminar» son SIEMPRE
// solo-icono (sin texto, solo su tooltip), sin importar cómo se invoque el
// botón. Así ninguna pantalla puede volver a mostrarlos como texto suelto.
// «nuevo» y «ver» respetan lo que pida quien los use (llevan texto porque su
// destino no es obvio: «Nuevo programa académico», «Malla», «Captura»).
//
// «cerrar» es solo-icono por omisión —la X sola se entiende—, pero acepta texto
// cuando hace falta decir QUÉ se cierra: el botón que abre el alta de un alumno
// se convierte en su propio cierre y ahí perder la palabra «Alumno» dejaría una
// X huérfana entre otras acciones.
const soloIconoEfectivo = computed(
    () => props.soloIcono
        || props.redondo
        || ['editar', 'eliminar'].includes(props.variante)
        || (props.variante === 'cerrar' && props.texto === undefined),
);

/*
 * El botón principal va relleno; los demás llevan el color en el BORDE y en el
 * texto, con el fondo transparente hasta que se pasa el cursor.
 *
 * Antes el fondo tintado era permanente. Con uno o dos botones se veía bien,
 * pero una lista de doce materias con tres acciones cada una son treinta y seis
 * pastillas de color compitiendo con lo que se viene a leer —el nombre de la
 * materia y su docente—. El borde da la misma identidad de color sin gritar; el
 * relleno se reserva para el momento en que el cursor está encima, que es
 * cuando esa acción sí es lo importante.
 */
const estilo = computed(() =>
    esPrimario.value
        ? { backgroundColor: cfg.value.color, color: 'var(--color-acento-texto)' }
        : {
            color: cfg.value.color,
            borderColor: `color-mix(in srgb, ${cfg.value.color} 35%, transparent)`,
            '--tinte': `color-mix(in srgb, ${cfg.value.color} 14%, transparent)`,
        },
);
</script>

<template>
    <component
        :is="href && !disabled ? Link : 'button'"
        :href="href && !disabled ? href : undefined"
        :type="href ? undefined : 'button'"
        :disabled="disabled || undefined"
        :title="soloIconoEfectivo ? etiqueta : undefined"
        :aria-label="soloIconoEfectivo ? etiqueta : undefined"
        class="boton-accion inline-flex items-center gap-1 transition disabled:cursor-not-allowed disabled:opacity-40"
        :class="[
            redondo ? 'rounded-full' : 'rounded-lg',
            fino ? 'font-normal' : 'font-medium',
            // Los secundarios miden lo mismo que `BotonExpediente` (30 px de
            // alto): conviven en la misma fila de un listado y desparejos se
            // ven como un descuido. Lo que los aligera no es el tamaño sino el
            // fondo transparente y la letra más chica.
            esPrimario
                ? 'px-3.5 py-2 text-sm shadow-sm hover:brightness-110'
                : 'boton-fantasma border text-xs',
            // El redondo es un cuadrado perfecto —si no, el círculo sale óvalo—.
            redondo ? 'h-8 w-8 justify-center p-0' : '',
            esPrimario || redondo ? '' : 'py-1.5',
            esPrimario || redondo ? '' : soloIconoEfectivo ? 'px-2' : 'px-3',
        ]"
        :style="estilo"
        @click="!href && emit('click')"
    >
        <svg
            v-if="!iconoAlFinal"
            class="icono-boton shrink-0"
            :class="esPrimario ? 'h-4 w-4' : 'h-3.5 w-3.5'"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.7"
            stroke="currentColor"
        >
            <path stroke-linecap="round" stroke-linejoin="round" :d="cfg.icono" />
        </svg>
        <!-- Los solo-icono no muestran texto (solo su tooltip): en ellos el
             gesto al pasar el cursor es únicamente la animación del icono. -->
        <span v-if="!soloIconoEfectivo">{{ etiqueta }}</span>
        <svg
            v-if="iconoAlFinal"
            class="icono-boton shrink-0"
            :class="esPrimario ? 'h-4 w-4' : 'h-3.5 w-3.5'"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.7"
            stroke="currentColor"
        >
            <path stroke-linecap="round" stroke-linejoin="round" :d="cfg.icono" />
        </svg>
    </component>
</template>

<style scoped>
/* El fantasma es borde y texto de su color; el relleno solo al pasar el cursor. */
.boton-fantasma {
    background-color: transparent;
}

.boton-fantasma:hover {
    background-color: var(--tinte);
}

/* La animación del icono ocurre al pasar el cursor (un salto sobrio hacia
   arriba): el mismo gesto en todas las acciones, incluido «Nuevo». */
.icono-boton {
    transition: transform 0.2s ease;
}

.boton-accion:hover .icono-boton {
    transform: translateY(-1px) scale(1.12);
}
</style>
