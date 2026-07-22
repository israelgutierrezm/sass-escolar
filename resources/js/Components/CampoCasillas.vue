<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Selección múltiple con casillas.
 *
 * Se prefiere a un `<select multiple>` nativo porque ese exige Ctrl+clic para
 * marcar varias opciones y para deseleccionar —cosa que casi nadie descubre— y
 * porque no deja ver de un vistazo qué está marcado sin desplazarse.
 *
 * El buscador aparece solo cuando la lista es larga; con cuatro campus estorba,
 * con cincuenta materias es indispensable.
 */
interface Opcion {
    valor: number;
    texto: string;
    ayuda?: string;
}

const props = withDefaults(
    defineProps<{
        etiqueta: string;
        opciones: Opcion[];
        error?: string;
        ayuda?: string;
        vacio?: string;
        /** A partir de cuántas opciones se muestra el buscador. */
        umbralBusqueda?: number;
    }>(),
    { umbralBusqueda: 8 },
);

const modelo = defineModel<number[]>({ default: () => [] });

const filtro = ref('');

const conBuscador = computed(() => props.opciones.length >= props.umbralBusqueda);

const visibles = computed(() => {
    const termino = filtro.value.trim().toLowerCase();

    if (termino === '') {
        return props.opciones;
    }

    return props.opciones.filter((opcion) => opcion.texto.toLowerCase().includes(termino));
});

const todasVisiblesMarcadas = computed(
    () => visibles.value.length > 0 && visibles.value.every((o) => modelo.value.includes(o.valor)),
);

function alternar(valor: number): void {
    modelo.value = modelo.value.includes(valor)
        ? modelo.value.filter((v) => v !== valor)
        : [...modelo.value, valor];
}

function alternarTodas(): void {
    const valores = visibles.value.map((o) => o.valor);

    modelo.value = todasVisiblesMarcadas.value
        ? modelo.value.filter((v) => !valores.includes(v))
        : [...new Set([...modelo.value, ...valores])];
}
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-2">
            <label class="block text-sm font-medium">{{ etiqueta }}</label>
            <button
                v-if="opciones.length > 1"
                type="button"
                class="text-xs"
                :style="{ color: 'var(--color-acento)' }"
                @click="alternarTodas"
            >
                {{ todasVisiblesMarcadas ? 'Quitar todas' : 'Marcar todas' }}
            </button>
        </div>

        <input
            v-if="conBuscador"
            v-model="filtro"
            type="search"
            placeholder="Buscar…"
            class="mt-2 w-full rounded-lg border px-3 py-1.5 text-sm"
            :style="{ borderColor: 'var(--color-borde)' }"
        />

        <div
            class="mt-2 max-h-64 space-y-1 overflow-y-auto rounded-lg border p-2"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <label
                v-for="opcion in visibles"
                :key="opcion.valor"
                class="flex cursor-pointer items-start gap-2 rounded px-2 py-1 text-sm"
            >
                <input
                    type="checkbox"
                    class="mt-0.5 rounded"
                    :checked="modelo.includes(opcion.valor)"
                    @change="alternar(opcion.valor)"
                />
                <span>
                    <span class="block">{{ opcion.texto }}</span>
                    <span v-if="opcion.ayuda" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ opcion.ayuda }}
                    </span>
                </span>
            </label>

            <p v-if="visibles.length === 0" class="px-2 py-3 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                {{ filtro ? 'Nada coincide con la búsqueda.' : (vacio ?? 'No hay opciones disponibles.') }}
            </p>
        </div>

        <p v-if="ayuda && !error" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ ayuda }}</p>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
