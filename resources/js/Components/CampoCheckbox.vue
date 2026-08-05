<script setup lang="ts">
/**
 * Una casilla con su etiqueta.
 *
 * El estilo del cuadrito vive en `app.css` sobre el propio elemento, así que
 * aquí no hay clases de color: cambiar el tema de la escuela lo cambia solo.
 * Antes llevaba `text-indigo-600` fijo y se quedaba morada en cualquier tema.
 *
 * La fila entera es la zona de clic y se tiñe al marcarse —`.fila-casilla`—,
 * que es lo que hace visible de un vistazo qué está dentro y qué no.
 */
defineProps<{ etiqueta: string; ayuda?: string; deshabilitado?: boolean }>();

const modelo = defineModel<boolean>();
</script>

<template>
    <label class="fila-casilla">
        <input
            v-model="modelo"
            type="checkbox"
            class="mt-0.5"
            :disabled="deshabilitado"
        />
        <span>
            <span class="block text-sm text-contenido">{{ etiqueta }}</span>
            <span v-if="ayuda" class="block text-xs text-suave">{{ ayuda }}</span>
        </span>
    </label>
</template>
