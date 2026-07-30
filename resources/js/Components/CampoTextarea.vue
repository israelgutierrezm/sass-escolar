<script setup lang="ts">
/**
 * Área de texto con etiqueta y error. La contraparte de CampoTexto para textos
 * largos (bienvenida, notas), para no repetir el mismo markup en cada pantalla.
 */
withDefaults(
    defineProps<{
        etiqueta: string;
        error?: string;
        requerido?: boolean;
        ayuda?: string;
        marcador?: string;
        filas?: number;
    }>(),
    { requerido: false, filas: 2 },
);

const modelo = defineModel<string | null>();
</script>

<template>
    <div>
        <label class="mb-1 block text-sm font-medium text-contenido">
            {{ etiqueta }}<span v-if="requerido" class="text-red-500"> *</span>
        </label>
        <textarea
            v-model="modelo"
            :required="requerido"
            :placeholder="marcador"
            :rows="filas"
            class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-1"
            :class="
                error
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
                    : 'border-borde focus:border-indigo-500 focus:ring-indigo-500'
            "
        ></textarea>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-suave">{{ ayuda }}</p>
    </div>
</template>
