<script setup lang="ts">
/**
 * El menú de tres puntos de la columna «Acciones».
 *
 * ── Por qué no van los botones sueltos ─────────────────────────────────────
 * Con el lápiz y el bote de basura a la vista, «Eliminar» está a UN clic de
 * distancia en cada renglón, al lado de una acción inocua y del mismo tamaño.
 * En una lista de veinte campus son veinte botes de basura pidiendo que se les
 * dé sin querer. Plegarlos cuesta un clic más y hace que borrar sea una
 * decisión: se abre el menú, se lee, se elige.
 *
 * ── El permiso lo sigue poniendo QUIEN LO USA ──────────────────────────────
 * Este componente no sabe de permisos y no debe saber: recibe la lista ya
 * filtrada, igual que antes se escribía `v-if="puedeEditar"` en cada botón.
 * Con la lista vacía NO se dibuja nada —ni el botón—: un menú que se abre sin
 * opciones es peor que ninguno, y es la regla de vacíos de este proyecto.
 *
 * ── El panel se TELETRANSPORTA, y no es un capricho ────────────────────────
 * Estas tablas viven dentro de un `overflow-x-auto` —hace falta: una tabla no
 * encoge por debajo de su contenido y sin él sus últimas columnas quedan
 * inalcanzables—. Un panel posicionado en absoluto dentro de ese contenedor se
 * RECORTA, y justo en la última columna, que es donde está. Va al `body` con
 * posición fija calculada del botón, y se cierra al desplazar porque entonces
 * dejaría de estar donde lo dejaron.
 */
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { ACCIONES, type VarianteAccion } from '@/utils/acciones';

export type OpcionAccion = {
    /** Lo que se emite al elegirla. Con `href` no hace falta. */
    clave?: string;
    variante: VarianteAccion;
    /** Por omisión, la de la variante («Editar», «Eliminar»…). */
    texto?: string;
    /** Con esto es un enlace de Inertia; sin esto, emite `elegir`. */
    href?: string;
    deshabilitado?: boolean;
    /** Por qué está deshabilitada. Sin esto, un botón apagado no se explica. */
    motivo?: string;
};

const props = defineProps<{ opciones: OpcionAccion[] }>();
const emit = defineEmits<{ elegir: [clave: string] }>();

const abierto = ref(false);
const boton = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const arriba = ref(0);
const derecha = ref(0);

const hayOpciones = computed(() => props.opciones.length > 0);

/**
 * Coloca el panel bajo el botón, alineado por su borde derecho.
 *
 * Si abajo no cabe, se abre HACIA ARRIBA: en el último renglón de una tabla
 * larga el panel se salía de la ventana y había que desplazar para leer sus
 * opciones —justo el renglón donde más incómodo resulta—.
 */
function colocar(): void {
    const caja = boton.value?.getBoundingClientRect();

    if (!caja) {
        return;
    }

    // Alto estimado: 38 px por opción más el respiro del panel. No hace falta
    // medirlo, sólo decidir el lado.
    const alto = props.opciones.length * 38 + 12;
    const cabeAbajo = caja.bottom + alto + 8 <= window.innerHeight;

    arriba.value = cabeAbajo ? caja.bottom + 4 : Math.max(8, caja.top - alto - 4);
    derecha.value = Math.max(8, window.innerWidth - caja.right);
}

function alternar(): void {
    if (abierto.value) {
        abierto.value = false;

        return;
    }

    colocar();
    abierto.value = true;
}

function cerrar(): void {
    abierto.value = false;
}

function elegir(opcion: OpcionAccion): void {
    if (opcion.deshabilitado) {
        return;
    }

    cerrar();

    if (opcion.clave !== undefined) {
        emit('elegir', opcion.clave);
    }
}

function alClicFuera(evento: MouseEvent): void {
    const destino = evento.target as Node;

    if (boton.value?.contains(destino) || panel.value?.contains(destino)) {
        return;
    }

    cerrar();
}

function alTeclear(evento: KeyboardEvent): void {
    if (evento.key === 'Escape') {
        cerrar();
    }
}

onMounted(() => {
    document.addEventListener('mousedown', alClicFuera);
    document.addEventListener('keydown', alTeclear);
    // `true` para enterarse del desplazamiento de CUALQUIER contenedor, no sólo
    // el de la ventana: la tabla se desplaza por su cuenta.
    window.addEventListener('scroll', cerrar, true);
    window.addEventListener('resize', cerrar);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', alClicFuera);
    document.removeEventListener('keydown', alTeclear);
    window.removeEventListener('scroll', cerrar, true);
    window.removeEventListener('resize', cerrar);
});
</script>

<template>
    <div v-if="hayOpciones" class="inline-flex">
        <button
            ref="boton"
            type="button"
            class="menu-acciones inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg border transition"
            :class="abierto ? 'esta-abierto' : ''"
            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
            :title="'Acciones'"
            aria-label="Acciones"
            aria-haspopup="menu"
            :aria-expanded="abierto"
            @click="alternar"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                <circle cx="12" cy="5" r="1.6" />
                <circle cx="12" cy="12" r="1.6" />
                <circle cx="12" cy="19" r="1.6" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="abierto"
                ref="panel"
                role="menu"
                class="fixed z-50 min-w-44 overflow-hidden rounded-lg border py-1 shadow-lg"
                :style="{
                    top: `${arriba}px`,
                    right: `${derecha}px`,
                    borderColor: 'var(--color-borde)',
                    backgroundColor: 'var(--color-superficie)',
                }"
            >
                <component
                    :is="opcion.href && !opcion.deshabilitado ? Link : 'button'"
                    v-for="(opcion, i) in opciones"
                    :key="opcion.clave ?? `o${i}`"
                    :href="opcion.href && !opcion.deshabilitado ? opcion.href : undefined"
                    :type="opcion.href ? undefined : 'button'"
                    :disabled="opcion.deshabilitado || undefined"
                    :title="opcion.motivo"
                    role="menuitem"
                    class="opcion flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-40"
                    :style="{ color: ACCIONES[opcion.variante].color, '--tinte': `color-mix(in srgb, ${ACCIONES[opcion.variante].color} 12%, transparent)` }"
                    @click="elegir(opcion)"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ACCIONES[opcion.variante].icono" />
                    </svg>
                    {{ opcion.texto ?? ACCIONES[opcion.variante].etiqueta }}
                </component>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.menu-acciones:hover,
.menu-acciones.esta-abierto {
    background-color: color-mix(in srgb, var(--color-suave) 12%, transparent);
}

.opcion:hover:not(:disabled) {
    background-color: var(--tinte);
}
</style>
