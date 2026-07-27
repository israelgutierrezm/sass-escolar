<script setup lang="ts">
/**
 * Tarjeta genérica para la vista de cuadrícula de un listado.
 *
 * Los listados de catálogo (campus, carreras, planes, oferta…) no tienen foto
 * como las personas, así que en lugar de repetir un `<article>` distinto en
 * cada página se centraliza aquí: un título, una clave opcional, una lista de
 * pares etiqueta/valor y dos ranuras —una para una insignia arriba a la derecha
 * y otra para los botones de acción al pie—. Así la cuadrícula se ve igual en
 * todos los módulos y responde igual en móvil.
 */
defineProps<{
    titulo: string;
    clave?: string | null;
    /** Pares etiqueta → valor que se listan en el cuerpo de la tarjeta. */
    metas?: { etiqueta: string; valor: string | number | null }[];
    /** Si se pasa, toda la tarjeta es un enlace a ese destino. */
    href?: string;
}>();
</script>

<template>
    <component
        :is="href ? 'a' : 'div'"
        :href="href"
        class="tarjeta flex flex-col gap-3 p-4"
        :class="href ? 'tarjeta-interactiva' : ''"
    >
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="truncate font-medium">{{ titulo }}</h3>
                <p v-if="clave" class="truncate font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ clave }}
                </p>
            </div>
            <slot name="insignia" />
        </div>

        <dl v-if="metas?.length" class="grid gap-1.5 text-sm">
            <div v-for="m in metas" :key="m.etiqueta" class="flex items-baseline justify-between gap-3">
                <dt class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">{{ m.etiqueta }}</dt>
                <dd class="min-w-0 truncate text-right">{{ m.valor ?? '—' }}</dd>
            </div>
        </dl>

        <!-- En la cuadrícula las acciones se separan a los extremos: editar a la
             izquierda, eliminar a la derecha (y lo que haya en medio, al centro). -->
        <div
            v-if="$slots.acciones"
            class="mt-auto flex items-center justify-between gap-1 border-t pt-3"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <slot name="acciones" />
        </div>
    </component>
</template>
