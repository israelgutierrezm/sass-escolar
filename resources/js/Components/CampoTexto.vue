<script setup lang="ts">
/**
 * Campo de texto con etiqueta y error. Evita repetir el mismo markup en cada
 * formulario del sistema.
 */
withDefaults(
    defineProps<{
        etiqueta: string;
        error?: string;
        tipo?: string;
        requerido?: boolean;
        ayuda?: string;
        marcador?: string;
        mono?: boolean;
        maximo?: number;
        /** Para mostrar un dato que el usuario no administra (lo fija la escuela). */
        deshabilitado?: boolean;
    }>(),
    { tipo: 'text', requerido: false, mono: false, deshabilitado: false },
);

const modelo = defineModel<string | number | null>();
</script>

<template>
    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            {{ etiqueta }}<span v-if="requerido" class="text-red-500"> *</span>
        </label>
        <input
            v-model="modelo"
            :type="tipo"
            :required="requerido"
            :placeholder="marcador"
            :maxlength="maximo"
            :disabled="deshabilitado"
            class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-1 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500"
            :class="[
                error
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
                    : 'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500',
                mono ? 'font-mono uppercase' : '',
            ]"
        />
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-slate-400">{{ ayuda }}</p>
    </div>
</template>
