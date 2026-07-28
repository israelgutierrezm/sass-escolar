<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

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
        variante: 'nuevo' | 'editar' | 'eliminar' | 'ver' | 'cerrar';
        href?: string;
        texto?: string;
        soloIcono?: boolean;
        disabled?: boolean;
    }>(),
    { soloIcono: false, disabled: false },
);

const emit = defineEmits<{ click: [] }>();

// Cada variante: su etiqueta por defecto, el color y el trazo del icono.
//  - «nuevo» sigue el ACENTO del tema (var), para que combine si se cambia de
//    tema; el resto llevan colores fijos porque su significado no cambia:
//    editar es un ámbar discreto, ver un azul, eliminar un rojo.
const CONFIG = {
    nuevo: {
        etiqueta: 'Nuevo',
        color: 'var(--color-acento)',
        icono: 'M12 4.5v15m7.5-7.5h-15',
    },
    editar: {
        etiqueta: 'Editar',
        color: '#B7791F',
        icono: 'm16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125',
    },
    eliminar: {
        etiqueta: 'Eliminar',
        color: '#dc2626',
        icono: 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
    },
    ver: {
        etiqueta: 'Ver',
        color: '#0077B6',
        icono: 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    },
    // «cerrar» es el reverso de «editar» en las tablas con edición en línea: el
    // lápiz se vuelve una X para plegar la fila. Color neutro del tema porque no
    // es una acción con carga (crear/editar/borrar) sino un simple «ocultar».
    cerrar: {
        etiqueta: 'Cerrar',
        color: 'var(--color-suave)',
        icono: 'M6 18 18 6M6 6l12 12',
    },
} as const;

const cfg = computed(() => CONFIG[props.variante]);
const etiqueta = computed(() => props.texto ?? cfg.value.etiqueta);
const esPrimario = computed(() => props.variante === 'nuevo');

// Regla homologada en TODO el sistema: «editar», «eliminar» y «cerrar» son
// SIEMPRE solo-icono (sin texto, solo su tooltip), sin importar cómo se invoque
// el botón. Así ninguna pantalla puede volver a mostrarlos como texto suelto.
// «nuevo» y «ver» respetan lo que pida quien los use (llevan texto porque su
// destino no es obvio: «Nueva carrera», «Malla», «Captura»).
const soloIconoEfectivo = computed(
    () => props.soloIcono || ['editar', 'eliminar', 'cerrar'].includes(props.variante),
);

// El botón principal va relleno; los demás, fantasma con su color y un fondo
// tenue PERMANENTE (mismo color al 12 %); el detalle de hover vive en el CSS.
const estilo = computed(() =>
    esPrimario.value
        ? { backgroundColor: cfg.value.color, color: 'var(--color-acento-texto)' }
        : { color: cfg.value.color, '--tinte': `color-mix(in srgb, ${cfg.value.color} 12%, transparent)` },
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
        class="boton-accion inline-flex items-center gap-1.5 rounded-lg text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40"
        :class="[
            esPrimario ? 'px-3.5 py-2 shadow-sm hover:brightness-110' : 'boton-fantasma py-1.5',
            esPrimario ? '' : soloIconoEfectivo ? 'px-2' : 'px-2.5',
        ]"
        :style="estilo"
        @click="!href && emit('click')"
    >
        <svg class="icono-boton h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" :d="cfg.icono" />
        </svg>
        <!-- Los solo-icono no muestran texto (solo su tooltip): en ellos el
             gesto al pasar el cursor es únicamente la animación del icono. -->
        <span v-if="!soloIconoEfectivo">{{ etiqueta }}</span>
    </component>
</template>

<style scoped>
/* Los fantasma llevan su fondo tenue SIEMPRE (mismo color al 12 %). */
.boton-fantasma {
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
