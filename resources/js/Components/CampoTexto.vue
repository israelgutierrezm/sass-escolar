<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Campo de texto con etiqueta y error. Evita repetir el mismo markup en cada
 * formulario del sistema. Si `tipo === 'password'`, muestra un botón de ojo para
 * revelar/ocultar la contraseña.
 */
const props = withDefaults(
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
        /**
         * El `step` de un campo numérico.
         *
         * Por omisión `any`, y hace falta: sin él el navegador usa `step=1` y
         * RECHAZA cualquier decimal —«los dos valores válidos más aproximados
         * son 8 y 9»— sin que el formulario llegue a enviarse. Con 67 campos
         * `tipo="number"` en el sistema, eso dejaba fuera todo sueldo con
         * centavos, toda calificación con décimas y todo porcentaje. Un campo
         * que de verdad sólo admita enteros pasa `paso="1"`; la validación de
         * verdad la hace el servidor.
         */
        paso?: string;
        /** Para mostrar un dato que el usuario no administra (lo fija la escuela). */
        deshabilitado?: boolean;
    }>(),
    { tipo: 'text', requerido: false, mono: false, deshabilitado: false, paso: 'any' },
);

const modelo = defineModel<string | number | null>();

// Revelar contraseña: solo aplica cuando el campo es de tipo password.
const esPassword = computed(() => props.tipo === 'password');
const revelado = ref(false);
const tipoEfectivo = computed(() => (esPassword.value && revelado.value ? 'text' : props.tipo));

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
        <div class="relative">
            <input
                v-bind="$attrs"
                v-model="modelo"
                :type="tipoEfectivo"
                :required="requerido"
                :placeholder="marcador"
                :maxlength="maximo"
                :step="tipo === 'number' ? paso : undefined"
                :disabled="deshabilitado"
                class="w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-1 disabled:cursor-not-allowed disabled:bg-fondo disabled:text-suave"
                :class="[
                    error
                        ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
                        : 'border-borde focus:border-indigo-500 focus:ring-indigo-500',
                    mono ? 'font-mono uppercase' : '',
                    esPassword ? 'pr-10' : '',
                ]"
            />
            <button
                v-if="esPassword"
                type="button"
                class="absolute inset-y-0 right-0 grid w-10 place-items-center text-suave transition hover:text-contenido"
                :aria-label="revelado ? 'Ocultar contraseña' : 'Ver contraseña'"
                :title="revelado ? 'Ocultar' : 'Ver'"
                tabindex="-1"
                @click="revelado = !revelado"
            >
                <svg v-if="!revelado" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88" />
                </svg>
            </button>
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-suave">{{ ayuda }}</p>
    </div>
</template>
