<script setup lang="ts">
import { computed } from 'vue';

/**
 * Botón PRINCIPAL de envío de formulario.
 *
 * El reverso de BotonAccion: aquel es para acciones de fila (editar/eliminar/
 * ver) y para «Nuevo» que ABRE un formulario; éste es para el botón que ENVÍA
 * el formulario (Crear, Guardar, Registrar…). Se separa porque un submit
 * necesita `type="submit"` —BotonAccion siempre es `type="button"` y rompería
 * el envío—.
 *
 * Homologa el mismo relleno de acento en toda la app y estandariza el estado de
 * carga: mientras `procesando`, se deshabilita, muestra un spinner y cambia el
 * texto a `cargando` («Guardando…» por defecto). El texto normal va por `texto`
 * o por el slot (para etiquetas dinámicas tipo «Guardar cambios» vs «Crear X»).
 */
const props = withDefaults(
    defineProps<{
        /** Etiqueta normal. Alternativa: el slot por defecto. */
        texto?: string;
        /** Etiqueta mientras se envía. */
        cargando?: string;
        /** Enlaza a `form.processing`: deshabilita y muestra el spinner. */
        procesando?: boolean;
        /** Deshabilita por otra razón (validación local incompleta, etc.). */
        deshabilitado?: boolean;
        /** `submit` (por defecto) envía el form; `button` solo emite `click`. */
        tipo?: 'submit' | 'button';
        /** Ícono de arranque: check (guardar), «+» (crear), «⊕» (crear-circulo) o ninguno. */
        icono?: 'guardar' | 'crear' | 'crear-circulo' | 'ninguno';
        /** Solo el ícono (sin texto): la etiqueta pasa al tooltip. Botón compacto. */
        soloIcono?: boolean;
    }>(),
    { cargando: 'Guardando…', procesando: false, deshabilitado: false, tipo: 'submit', icono: 'guardar', soloIcono: false },
);

const emit = defineEmits<{ click: [] }>();

const ICONOS = {
    guardar: 'm4.5 12.75 6 6 9-13.5', // check
    crear: 'M12 4.5v15m7.5-7.5h-15', // +
    'crear-circulo': 'M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z', // plus-circle
    ninguno: '',
} as const;

const iconoPath = computed(() => ICONOS[props.icono]);
const inhabilitado = computed(() => props.procesando || props.deshabilitado);
</script>

<template>
    <button
        :type="tipo"
        :disabled="inhabilitado || undefined"
        :title="soloIcono ? texto : undefined"
        :aria-label="soloIcono ? texto : undefined"
        class="boton-principal inline-flex items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-medium shadow-sm transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-60"
        :class="soloIcono ? 'px-2.5' : 'px-4'"
        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
        @click="emit('click')"
    >
        <!-- Mientras procesa, un spinner reemplaza al ícono. -->
        <svg v-if="procesando" class="h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <svg
            v-else-if="iconoPath"
            class="icono-principal h-4 w-4 shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.7"
            stroke="currentColor"
        >
            <path stroke-linecap="round" stroke-linejoin="round" :d="iconoPath" />
        </svg>

        <template v-if="!soloIcono">
            <span v-if="procesando">{{ cargando }}</span>
            <span v-else><slot>{{ texto }}</slot></span>
        </template>
    </button>
</template>

<style scoped>
/* Mismo gesto que BotonAccion: el ícono salta un poco al pasar el cursor. */
.icono-principal {
    transition: transform 0.2s ease;
}

.boton-principal:hover .icono-principal {
    transform: translateY(-1px) scale(1.12);
}
</style>
