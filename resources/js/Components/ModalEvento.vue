<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * El detalle de un evento del calendario.
 *
 * ── Modal y no pantalla aparte ─────────────────────────────────────────────
 * Se consulta desde el mes y desde la agenda lateral, y en los dos casos el
 * contexto importa: quien mira agosto para ver qué se junta esa semana no
 * quiere perder el mes de vista para leer tres líneas. Una pantalla propia
 * obligaría a volver, y a volver al mes correcto.
 *
 * ── Las acciones las pone quien lo abre ────────────────────────────────────
 * Este componente sólo MUESTRA. Editar y eliminar entran por el slot
 * `acciones`, así que la misma pieza sirve para el administrador —que puede
 * tocar— y para el alumno, que sólo lee. No hay un `puedeEditar` que la vista
 * tenga que acertar: si no se pasan acciones, no hay acciones.
 */
defineProps<{
    evento: {
        titulo: string;
        etiqueta?: string | null;
        color?: string | null;
        fecha?: string | null;
        hora?: string | null;
        termina?: string | null;
        detalle?: string | null;
        borrador?: boolean;
        no_laborable?: boolean;
    };
}>();

const emit = defineEmits<{ cerrar: [] }>();

const caja = ref<HTMLElement | null>(null);

/** Adónde devolver el foco al cerrar: normalmente el evento que se pulsó. */
let veniaDe: HTMLElement | null = null;

/** Lo que se puede enfocar dentro del modal, en orden de tabulación. */
function enfocables(): HTMLElement[] {
    if (caja.value === null) return [];

    return [...caja.value.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
    )].filter((el) => el.offsetParent !== null);
}

/*
 * Escape cierra, y el tabulador no se escapa.
 *
 * ── Por qué el foco tiene que quedarse dentro ──────────────────────────────
 * Sin esto, tabular desde el modal lleva al contenido de ATRÁS: quien navega
 * con teclado —o con lector de pantalla— sigue leyendo el panel que el velo
 * está tapando, sin forma de saber que hay un diálogo abierto ni de volver a
 * él. La página se vuelve inutilizable justo para quien no puede rodearla con
 * el ratón.
 *
 * Se cicla a mano y no con `inert` en el resto del documento porque el modal
 * viaja por Teleport al body: marcar «todo lo demás» sería marcar hermanos que
 * este componente no debería conocer.
 */
function alTeclear(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
        emit('cerrar');

        return;
    }

    if (e.key !== 'Tab') return;

    const lista = enfocables();

    if (lista.length === 0) return;

    const primero = lista[0];
    const ultimo = lista[lista.length - 1];
    const actual = document.activeElement;

    // Salir por un extremo entra por el otro. Y si el foco se había ido fuera
    // —al abrir, o tras un clic en el velo—, se recupera al primero.
    if (e.shiftKey && (actual === primero || ! caja.value?.contains(actual))) {
        e.preventDefault();
        ultimo.focus();
    } else if (! e.shiftKey && (actual === ultimo || ! caja.value?.contains(actual))) {
        e.preventDefault();
        primero.focus();
    }
}

/*
 * Con el modal abierto, el fondo no se desplaza.
 *
 * Sin esto la rueda del ratón mueve la página de atrás mientras se lee la
 * ficha, que se siente como si el modal se despegara. Y de paso desaparece la
 * barra de desplazamiento, así que el velo cubre el ancho completo y la ficha
 * queda centrada de verdad y no diez píxeles a la izquierda.
 *
 * Se compensa el hueco de la barra con padding para que el contenido de atrás
 * no dé un salto lateral al abrir y otro al cerrar.
 */
onMounted(() => {
    document.addEventListener('keydown', alTeclear);

    /*
     * El foco entra al diálogo. Sin esto sigue en el evento que se pulsó, que
     * está detrás del velo: el primer tabulador movería por la página oculta.
     */
    veniaDe = document.activeElement as HTMLElement | null;
    enfocables()[0]?.focus();

    const barra = window.innerWidth - document.documentElement.clientWidth;

    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = barra > 0 ? `${barra}px` : '';
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', alTeclear);

    // De vuelta a donde estaba: cerrar y perder el sitio obliga a tabular desde
    // el principio de la página.
    veniaDe?.focus();
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
});
</script>

<template>
    <!--
        `Teleport` al body: sin esto el modal se queda DENTRO del <main>.

        `position: fixed` deja de referirse a la ventana en cuanto un ancestro
        tiene `transform`, `filter` o `backdrop-filter` —el layout usa varios—,
        y entonces `inset-0` cubre sólo el área de contenido: el velo respetaba
        la barra lateral y el encabezado, y la ficha quedaba centrada respecto
        de una caja, no de la pantalla. Sacándolo del árbol, el único ancestro
        es el body.
    -->
    <Teleport to="body">
        <!-- El fondo cierra al pulsarlo; el contenido no, por eso el `.self`. -->
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
            @click.self="emit('cerrar')"
        >
        <div
            ref="caja"
            class="tarjeta w-full max-w-lg overflow-hidden"
            role="dialog"
            aria-modal="true"
            :aria-label="evento.titulo"
        >
            <!-- Franja del color del evento: identifica el tipo antes de leer. -->
            <div class="h-1.5" :style="{ backgroundColor: evento.color ?? 'var(--color-acento)' }" />

            <div class="p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span
                                v-if="evento.etiqueta"
                                class="rounded-full px-2.5 py-0.5 font-medium"
                                :style="{
                                    backgroundColor: `color-mix(in srgb, ${evento.color ?? 'var(--color-acento)'} 14%, transparent)`,
                                    color: evento.color ?? 'var(--color-acento)',
                                }"
                            >
                                {{ evento.etiqueta }}
                            </span>
                            <span v-if="evento.borrador" class="rounded-full bg-amber-50 px-2.5 py-0.5 font-medium text-amber-800">
                                Borrador · no lo ve nadie
                            </span>
                            <span v-if="evento.no_laborable" class="rounded-full bg-red-50 px-2.5 py-0.5 font-medium text-red-700">
                                Día no laborable
                            </span>
                        </div>

                        <h2 class="mt-2 text-lg font-semibold text-contenido">{{ evento.titulo }}</h2>

                        <p class="mt-1 text-sm text-suave">
                            {{ evento.fecha }}
                            <template v-if="evento.hora"> · {{ evento.hora }}</template>
                            <template v-if="evento.termina">–{{ evento.termina }}</template>
                        </p>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 rounded-lg p-1 text-suave transition hover:bg-[color-mix(in_srgb,var(--color-acento)_8%,transparent)]"
                        title="Cerrar"
                        @click="emit('cerrar')"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <p v-if="evento.detalle" class="mt-4 whitespace-pre-line text-sm text-contenido">
                    {{ evento.detalle }}
                </p>

                <!--
                    Las acciones sólo existen si quien abre el modal las pone.
                    Para el alumno el slot va vacío y el pie ni siquiera se
                    dibuja, en vez de enseñarle botones deshabilitados.
                -->
                <div v-if="$slots.acciones" class="mt-5 flex flex-wrap justify-end gap-2 border-t border-borde pt-4">
                    <slot name="acciones" />
                </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
