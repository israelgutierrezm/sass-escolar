<script setup lang="ts">
defineProps<{ etiqueta: string; ayuda?: string }>();

const modelo = defineModel<boolean>();
</script>

<template>
    <label class="flex items-start gap-2">
        <input
            v-model="modelo"
            type="checkbox"
            class="mt-0.5 rounded border-borde text-indigo-600 focus:ring-indigo-500"
        />
        <span>
            <span class="block text-sm text-contenido">{{ etiqueta }}</span>
            <span v-if="ayuda" class="block text-xs text-suave">{{ ayuda }}</span>
        </span>
    </label>
</template>
