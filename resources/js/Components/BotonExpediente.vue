<script setup lang="ts">
/**
 * Botón de acción de una fila de listado: enlace «Expediente →» con chevrones
 * que fluyen a la derecha al pasar el cursor (por la fila o por el botón),
 * igual que la flecha del botón de iniciar sesión.
 *
 * El estilo del hover (relleno de acento) y la animación de los chevrones viven
 * en `app.css` (`.boton-expediente`, `.flechas-fila`), para que funcionen dentro
 * de las filas `.fila-nueva` sin depender de estilos scoped.
 */
withDefaults(defineProps<{ href: string; texto?: string }>(), { texto: 'Expediente' });
</script>

<template>
    <a
        :href="href"
        class="boton-expediente inline-flex items-center gap-1.5 rounded-lg border border-borde px-3 py-1.5 text-xs font-medium transition-colors"
        :style="{ color: 'var(--color-acento)' }"
    >
        {{ texto }}
        <span class="flechas-fila" aria-hidden="true">
            <svg v-for="n in 3" :key="n" class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
            </svg>
        </span>
    </a>
</template>
