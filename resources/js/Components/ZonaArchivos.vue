<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Zona de carga de VARIOS archivos: arrastrar-y-soltar o clic.
 *
 * Hermana de {@see ZonaArchivo}, que es para uno solo —el `.cer` de titulación—.
 * Se separan porque el caso de uno tiene forma propia: la zona desaparece en
 * cuanto hay archivo y se cambia con un enlace. Con varios hay que poder añadir,
 * ver la lista y quitar de a uno, y meter las dos cosas en un componente lo
 * habría llenado de condicionales.
 *
 * ── Por qué arrastrar y no sólo el `<input type=file>` ─────────────────────
 * El input suelto del navegador no dice cuántos archivos caben, ni cuánto puede
 * pesar cada uno, ni qué se lleva ya elegido: el alumno suelta el PDF, ve
 * «2 archivos» en gris y entrega a ciegas. Aquí se ve lo que va a mandar, con
 * su peso, y se puede quitar antes de entregar.
 */
const props = withDefaults(
    defineProps<{
        /** Los archivos elegidos (v-model). */
        modelValue: File[];
        /** Filtro del selector: 'image/*,application/pdf'… */
        accept?: string;
        /** Cuántos admite el servidor. Se avisa al pasarse, no se recorta en silencio. */
        max?: number;
        /** Tope por archivo, en MB. */
        maxMb?: number;
    }>(),
    { accept: '', max: 5, maxMb: 20 },
);

const emit = defineEmits<{ 'update:modelValue': [File[]] }>();

const arrastrando = ref(false);
const entrada = ref<HTMLInputElement | null>(null);
const aviso = ref<string | null>(null);

function abrir(): void {
    entrada.value?.click();
}

/**
 * Suma los que llegan a los que ya había.
 *
 * Se acumulan en vez de reemplazar porque es lo que uno espera al soltar un
 * segundo archivo: el `<input type=file>` crudo hace lo contrario —la segunda
 * selección borra la primera— y ahí es donde la gente pierde adjuntos sin
 * enterarse.
 */
function agregar(llegan: FileList | null | undefined): void {
    if (!llegan?.length) return;

    aviso.value = null;

    const nuevos: File[] = [];
    const pesados: string[] = [];

    for (const archivo of Array.from(llegan)) {
        if (archivo.size > props.maxMb * 1024 * 1024) {
            pesados.push(archivo.name);

            continue;
        }

        // Mismo nombre y mismo peso: es el mismo archivo soltado dos veces.
        const repetido = props.modelValue.some(
            (y) => y.name === archivo.name && y.size === archivo.size,
        );

        if (! repetido) nuevos.push(archivo);
    }

    const total = [...props.modelValue, ...nuevos];

    if (pesados.length) {
        aviso.value = `${pesados.join(', ')}: pasa${pesados.length > 1 ? 'n' : ''} de ${props.maxMb} MB.`;
    }

    if (total.length > props.max) {
        aviso.value = `Sólo se pueden adjuntar ${props.max} archivos.`;
        emit('update:modelValue', total.slice(0, props.max));

        return;
    }

    emit('update:modelValue', total);
}

function alSeleccionar(evento: Event): void {
    const entradaHtml = evento.target as HTMLInputElement;

    agregar(entradaHtml.files);

    // Se limpia para que elegir DOS VECES el mismo archivo vuelva a disparar el
    // evento; si no, parece que el botón dejó de responder.
    entradaHtml.value = '';
}

function alSoltar(evento: DragEvent): void {
    arrastrando.value = false;
    agregar(evento.dataTransfer?.files);
}

function quitar(indice: number): void {
    const quedan = [...props.modelValue];

    quedan.splice(indice, 1);
    aviso.value = null;
    emit('update:modelValue', quedan);
}

const lleno = computed(() => props.modelValue.length >= props.max);

function peso(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <div>
        <input ref="entrada" type="file" multiple :accept="accept" class="hidden" @change="alSeleccionar" />

        <div
            v-if="!lleno"
            class="zona"
            :class="{ 'zona--activa': arrastrando }"
            role="button"
            tabindex="0"
            @click="abrir"
            @keydown.enter.prevent="abrir"
            @dragover.prevent="arrastrando = true"
            @dragenter.prevent="arrastrando = true"
            @dragleave.prevent="arrastrando = false"
            @drop.prevent="alSoltar"
        >
            <svg class="mx-auto h-9 w-9" :style="{ color: 'var(--color-acento)' }" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            <p class="mt-2 text-sm font-medium">
                Arrastra tus archivos aquí o <span :style="{ color: 'var(--color-acento)' }">búscalos en tu equipo</span>
            </p>
            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                Hasta {{ max }} archivos, {{ maxMb }} MB cada uno.
            </p>
        </div>

        <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
            Llegaste al máximo de {{ max }} archivos. Quita uno para cambiarlo.
        </p>

        <!-- Lo que se va a mandar: se ve antes de entregar, no después. -->
        <ul v-if="modelValue.length" class="mt-3 space-y-1.5">
            <li
                v-for="(archivo, i) in modelValue"
                :key="`${archivo.name}-${archivo.size}-${i}`"
                class="flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <svg class="h-4 w-4 shrink-0" :style="{ color: 'var(--color-acento)' }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                <span class="min-w-0 flex-1 truncate text-contenido">{{ archivo.name }}</span>
                <span class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">{{ peso(archivo.size) }}</span>
                <button
                    type="button"
                    class="shrink-0 rounded p-1 transition hover:bg-[color-mix(in_srgb,#dc2626_10%,transparent)]"
                    :style="{ color: 'var(--color-suave)' }"
                    :title="`Quitar ${archivo.name}`"
                    @click="quitar(i)"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </li>
        </ul>

        <p v-if="aviso" class="mt-2 text-xs" :style="{ color: '#d97706' }">{{ aviso }}</p>
    </div>
</template>

<style scoped>
.zona {
    display: block;
    width: 100%;
    cursor: pointer;
    border-radius: 0.75rem;
    border: 2px dashed var(--color-borde);
    padding: 1.5rem 1rem;
    text-align: center;
    transition:
        border-color 0.15s ease,
        background-color 0.15s ease;
}

.zona:hover,
.zona:focus-visible,
.zona--activa {
    outline: none;
    border-color: var(--color-acento);
    background-color: color-mix(in srgb, var(--color-acento) 6%, transparent);
}
</style>
