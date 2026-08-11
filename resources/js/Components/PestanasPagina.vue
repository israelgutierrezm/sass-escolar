<script setup lang="ts">
/**
 * Pestañas DENTRO de una página (cambian el contenido visible, no navegan).
 *
 * Comparten exactamente el aspecto de las pestañas de sección
 * ({@see PestanasSeccion}): borde inferior, activo en acento con subrayado,
 * hover suave. Así todas las pestañas del sistema —de sección o de contenido—
 * se ven idénticas. La diferencia es que aquí son botones con `v-model`, no
 * enlaces de navegación.
 */
defineProps<{
    pestanas: { clave: string; etiqueta: string }[];
    modelValue: string;
}>();

defineEmits<{ 'update:modelValue': [string] }>();
</script>

<template>
    <nav class="mb-6 flex flex-wrap border-b" :style="{ borderColor: 'var(--color-borde)' }">
        <button
            v-for="p in pestanas"
            :key="p.clave"
            type="button"
            class="tab relative px-3 py-2.5 text-sm transition-colors"
            :class="modelValue === p.clave ? 'tab-activa font-semibold' : ''"
            :style="{ color: modelValue === p.clave ? 'var(--color-acento)' : 'var(--color-suave)' }"
            @click="$emit('update:modelValue', p.clave)"
        >
            {{ p.etiqueta }}
            <span
                v-if="modelValue === p.clave"
                class="absolute inset-x-2 -bottom-px h-0.5 rounded-full"
                :style="{ backgroundColor: 'var(--color-acento)' }"
            />
        </button>
    </nav>
</template>

<style scoped>
/* Realce suave al pasar el cursor sobre una pestaña inactiva. */
.tab:not(.tab-activa):hover {
    color: var(--color-contenido) !important;
}
</style>
