<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * Selección única con búsqueda por texto.
 *
 * Existe para las listas que un `<select>` vuelve impracticables: una escuela
 * con doscientos docentes obliga a desplazarse por un desplegable buscando un
 * apellido. Aquí se teclea y la lista se reduce.
 *
 * Las opciones ya usadas se pueden marcar como `deshabilitada` con su razón, en
 * vez de desaparecer: ver "Ya es titular" al lado de un nombre explica por qué
 * no se puede elegir; que el nombre no aparezca solo hace dudar de si la
 * persona está dada de alta.
 */
interface Opcion {
    valor: number;
    texto: string;
    ayuda?: string;
    deshabilitada?: boolean;
    razon?: string;
}

const props = withDefaults(
    defineProps<{
        etiqueta: string;
        opciones: Opcion[];
        marcador?: string;
        error?: string;
        ayuda?: string;
        vacio?: string;
    }>(),
    { marcador: 'Escribe para buscar…' },
);

const modelo = defineModel<number | null>();

const filtro = ref('');
const abierto = ref(false);

const seleccionada = computed(() => props.opciones.find((o) => o.valor === modelo.value) ?? null);

const visibles = computed(() => {
    const termino = filtro.value.trim().toLowerCase();

    if (termino === '') {
        return props.opciones.slice(0, 50);
    }

    return props.opciones
        .filter((o) => o.texto.toLowerCase().includes(termino))
        .slice(0, 50);
});

// Si el padre limpia la selección (tras guardar), se limpia también el texto.
watch(modelo, (valor) => {
    if (valor === null || valor === undefined) {
        filtro.value = '';
    }
});

function elegir(opcion: Opcion): void {
    if (opcion.deshabilitada) {
        return;
    }

    modelo.value = opcion.valor;
    filtro.value = '';
    abierto.value = false;
}

function limpiar(): void {
    modelo.value = null;
    filtro.value = '';
}
</script>

<template>
    <div class="relative">
        <label class="block text-sm font-medium">{{ etiqueta }}</label>

        <!-- Con algo elegido se muestra el nombre, no la caja de búsqueda:
             el usuario ya decidió y necesita ver qué decidió. -->
        <div
            v-if="seleccionada"
            class="mt-1 flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <span>{{ seleccionada.texto }}</span>
            <button
                type="button"
                class="text-xs"
                :style="{ color: 'var(--color-suave)' }"
                @click="limpiar"
            >
                Cambiar
            </button>
        </div>

        <template v-else>
            <input
                v-model="filtro"
                type="search"
                :placeholder="marcador"
                class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @focus="abierto = true"
            />

            <ul
                v-if="abierto"
                class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border shadow-lg"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo, #fff)' }"
            >
                <li v-for="opcion in visibles" :key="opcion.valor">
                    <button
                        type="button"
                        :disabled="opcion.deshabilitada"
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50"
                        @click="elegir(opcion)"
                    >
                        <span>
                            {{ opcion.texto }}
                            <span v-if="opcion.ayuda" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ opcion.ayuda }}
                            </span>
                        </span>
                        <span v-if="opcion.razon" class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ opcion.razon }}
                        </span>
                    </button>
                </li>

                <li v-if="visibles.length === 0" class="px-3 py-3 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ filtro ? 'Nadie coincide con la búsqueda.' : (vacio ?? 'No hay opciones.') }}
                </li>
            </ul>
        </template>

        <p v-if="ayuda && !error" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ ayuda }}</p>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
