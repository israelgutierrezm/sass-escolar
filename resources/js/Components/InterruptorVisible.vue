<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Publicar o esconder algo desde su renglón, de un toque.
 *
 * El docente arma el curso en borrador y lo va soltando conforme avanza el
 * semestre: es el gesto que más repite. Hacerlo por el formulario obliga a
 * abrirlo, encontrar la casilla, guardar —y reenvía todos los campos, con lo
 * que un descuido puede pisar lo que no se venía a tocar—.
 *
 * El ojo dice el estado ACTUAL, no la acción: abierto = lo ven; tachado = no.
 * Es la convención de todas partes y el título aclara qué pasa al pulsarlo, que
 * es donde un ícono solo se queda corto.
 */
const props = defineProps<{
    /** A dónde va el PATCH. */
    url: string;
    /** Si hoy lo ven los alumnos. */
    publicada: boolean;
    /** Para el título: «“Tarea 1” ya la ven tus alumnos». */
    titulo?: string;
    /** Qué son los que ven: alumnos (materia) o grupos (plantilla). */
    audiencia?: string;
}>();

const enviando = ref(false);

function alternar(): void {
    enviando.value = true;

    router.patch(
        props.url,
        { publicada: !props.publicada },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <button
        type="button"
        class="grid h-8 w-8 place-items-center rounded-lg transition disabled:opacity-50"
        :class="publicada
            ? 'hover:bg-[color-mix(in_srgb,#16a34a_12%,transparent)]'
            : 'hover:bg-[color-mix(in_srgb,var(--color-suave)_14%,transparent)]'"
        :style="{ color: publicada ? '#16a34a' : 'var(--color-suave)' }"
        :disabled="enviando"
        :title="publicada
            ? `Visible${audiencia ? ' para ' + audiencia : ''}. Pulsa para esconder${titulo ? ' «' + titulo + '»' : ''}.`
            : `Oculta${audiencia ? ' para ' + audiencia : ''}. Pulsa para publicar${titulo ? ' «' + titulo + '»' : ''}.`"
        :aria-pressed="publicada"
    >
        <!-- Ojo abierto: lo ven. -->
        <svg v-if="publicada" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
            />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>

        <!-- Ojo tachado: sólo el docente la ve. -->
        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"
            />
        </svg>
    </button>
</template>
