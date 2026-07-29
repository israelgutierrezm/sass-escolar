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
        /** Nota al pasar el cursor sobre la ⓘ junto a la etiqueta. */
        tooltip?: string;
        marcador?: string;
        mono?: boolean;
        maximo?: number;
        /** Para mostrar un dato que el usuario no administra (lo fija la escuela). */
        deshabilitado?: boolean;
    }>(),
    { tipo: 'text', requerido: false, mono: false, deshabilitado: false },
);

const modelo = defineModel<string | number | null>();

/**
 * Los atributos y escuchas van al INPUT, no al div de fuera. Sin esto, un
 * `@blur` puesto sobre el componente no se dispara nunca: se engancha al div y
 * `blur` no burbujea.
 */
defineOptions({ inheritAttrs: false });
</script>

<template>
    <div>
        <label class="mb-1 flex items-center gap-1 text-sm font-medium text-contenido">
            <span>{{ etiqueta }}<span v-if="requerido" class="text-red-500"> *</span></span>
            <span
                v-if="tooltip"
                :title="tooltip"
                class="inline-grid h-4 w-4 cursor-help place-items-center rounded-full text-[10px] font-bold text-suave ring-1 ring-borde"
                aria-label="Más información"
            >
                i
            </span>
        </label>
        <input
            v-bind="$attrs"
            v-model="modelo"
            :type="tipo"
            :required="requerido"
            :placeholder="marcador"
            :maxlength="maximo"
            :disabled="deshabilitado"
            class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-1 disabled:cursor-not-allowed disabled:bg-fondo disabled:text-suave"
            :class="[
                error
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
                    : 'border-borde focus:border-indigo-500 focus:ring-indigo-500',
                mono ? 'font-mono uppercase' : '',
            ]"
        />
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-suave">{{ ayuda }}</p>
    </div>
</template>
