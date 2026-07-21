<script setup lang="ts">
/**
 * Selector con etiqueta y error. Las opciones se pasan como {valor, texto}
 * para no atarse a la forma de cada catálogo.
 */
withDefaults(
    defineProps<{
        etiqueta: string;
        opciones: { valor: string | number; texto: string }[];
        error?: string;
        requerido?: boolean;
        vacio?: string;
        ayuda?: string;
    }>(),
    { requerido: false },
);

const modelo = defineModel<string | number | null>();
</script>

<template>
    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">
            {{ etiqueta }}<span v-if="requerido" class="text-red-500"> *</span>
        </label>
        <select
            v-model="modelo"
            :required="requerido"
            class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-1"
            :class="
                error
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
                    : 'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500'
            "
        >
            <option v-if="vacio" :value="null">{{ vacio }}</option>
            <option v-for="opcion in opciones" :key="opcion.valor" :value="opcion.valor">
                {{ opcion.texto }}
            </option>
        </select>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-slate-400">{{ ayuda }}</p>
    </div>
</template>
