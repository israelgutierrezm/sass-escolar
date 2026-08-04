<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * El caparazón de cualquier diálogo: velo, foco y teclado.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * Un modal no es una tarjeta con fondo oscuro. Para estar bien hecho tiene que
 * salir del árbol —o `position: fixed` no cubre la pantalla—, atrapar el foco
 * —o el tabulador se va al contenido de atrás—, cerrar con Escape, bloquear el
 * desplazamiento del fondo y devolver el foco al cerrar. Son cinco cosas, todas
 * fáciles de olvidar, y ya se habían olvidado una vez: el modal de la lista del
 * día no hacía ninguna salvo el velo.
 *
 * Aquí viven las cinco, una sola vez. Quien necesite un diálogo pone dentro su
 * contenido y no vuelve a pensar en esto.
 */
withDefaults(defineProps<{ etiqueta: string; ancho?: string }>(), {
    ancho: 'max-w-lg',
});

const emit = defineEmits<{ cerrar: [] }>();

const caja = ref<HTMLElement | null>(null);

/** Adónde devolver el foco al cerrar: normalmente el elemento que lo abrió. */
let veniaDe: HTMLElement | null = null;

/** Lo que se puede enfocar dentro, en orden de tabulación. */
function enfocables(): HTMLElement[] {
    if (caja.value === null) return [];

    return [...caja.value.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
    )].filter((el) => el.offsetParent !== null);
}

/*
 * Escape cierra, y el tabulador no se escapa.
 *
 * Sin lo segundo, tabular desde el diálogo lleva al contenido de ATRÁS: quien
 * navega con teclado —o con lector de pantalla— sigue leyendo lo que el velo
 * está tapando, sin saber que hay un diálogo abierto ni cómo volver a él.
 *
 * Se cicla a mano y no marcando el resto del documento con `inert` porque esto
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
    const fuera = ! caja.value?.contains(actual);

    if (e.shiftKey && (actual === primero || fuera)) {
        e.preventDefault();
        ultimo.focus();
    } else if (! e.shiftKey && (actual === ultimo || fuera)) {
        e.preventDefault();
        primero.focus();
    }
}

onMounted(() => {
    document.addEventListener('keydown', alTeclear);

    // El foco entra al diálogo: si se queda en lo que lo abrió —detrás del
    // velo— el primer tabulador ya se pierde.
    veniaDe = document.activeElement as HTMLElement | null;
    enfocables()[0]?.focus();

    /*
     * El fondo no se desplaza. Se compensa el ancho de la barra con padding
     * para que el contenido de atrás no dé un salto lateral al abrir y otro al
     * cerrar; sin barra, además, el diálogo queda centrado de verdad.
     */
    const barra = window.innerWidth - document.documentElement.clientWidth;

    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = barra > 0 ? `${barra}px` : '';
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', alTeclear);
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';

    // De vuelta a donde estaba: cerrar y perder el sitio obliga a tabular desde
    // el principio de la página.
    veniaDe?.focus();
});
</script>

<template>
    <!--
        `Teleport` al body. `position: fixed` deja de referirse a la ventana en
        cuanto un ancestro tiene `transform`, `filter` o `backdrop-filter` —el
        layout usa varios—, y entonces el velo cubriría sólo el área de
        contenido, respetando la barra lateral y el encabezado.
    -->
    <Teleport to="body">
        <!-- El fondo cierra al pulsarlo; el contenido no, por eso el `.self`. -->
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm"
            @click.self="emit('cerrar')"
        >
            <div
                ref="caja"
                class="tarjeta w-full overflow-hidden"
                :class="ancho"
                role="dialog"
                aria-modal="true"
                :aria-label="etiqueta"
            >
                <slot />
            </div>
        </div>
    </Teleport>
</template>
