<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Filtros a demanda: un botón los despliega, una casilla activa cada uno y
 * solo entonces aparece su selector.
 *
 * Se hizo así en vez de dejar los desplegables siempre visibles porque el
 * encabezado de un listado con cuatro o cinco filtros ocupa más pantalla que
 * los resultados, y en la mayoría de las búsquedas no se usa ninguno. Los que
 * SÍ están aplicados se ven siempre como fichas con su "×", aunque el panel
 * esté cerrado: un filtro activo escondido es la causa clásica del "no aparece
 * el alumno que busco".
 */
interface Filtro {
    clave: string;
    etiqueta: string;
    opciones: { valor: number | string; texto: string }[];
}

const props = defineProps<{
    filtros: Filtro[];
    /** Valores actuales, por clave. null/'' = sin aplicar. */
    valores: Record<string, number | string | null>;
}>();

const emit = defineEmits<{
    (evento: 'cambio', valores: Record<string, number | string | null>): void;
}>();

const abierto = ref(false);

/** Un filtro se "activa" al marcarlo; se desactiva al quitar la marca. */
const activos = ref<Record<string, boolean>>(
    Object.fromEntries(props.filtros.map((f) => [f.clave, props.valores[f.clave] != null && props.valores[f.clave] !== ''])),
);

const aplicados = computed(() =>
    props.filtros
        .filter((f) => props.valores[f.clave] != null && props.valores[f.clave] !== '')
        .map((f) => ({
            clave: f.clave,
            etiqueta: f.etiqueta,
            texto: f.opciones.find((o) => String(o.valor) === String(props.valores[f.clave]))?.texto ?? '',
        })),
);

function alternar(filtro: Filtro): void {
    activos.value[filtro.clave] = !activos.value[filtro.clave];

    // Desmarcar limpia el valor: dejarlo puesto pero oculto haría que la lista
    // siguiera filtrada sin que se vea por qué.
    if (!activos.value[filtro.clave] && props.valores[filtro.clave] != null) {
        emit('cambio', { ...props.valores, [filtro.clave]: null });
    }
}

function elegir(clave: string, valor: string): void {
    emit('cambio', { ...props.valores, [clave]: valor === '' ? null : valor });
}

function quitar(clave: string): void {
    activos.value[clave] = false;
    emit('cambio', { ...props.valores, [clave]: null });
}

function limpiarTodos(): void {
    const vacios = Object.fromEntries(props.filtros.map((f) => [f.clave, null]));

    props.filtros.forEach((f) => (activos.value[f.clave] = false));
    emit('cambio', vacios);
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                :style="{
                    borderColor: aplicados.length ? 'var(--color-acento)' : 'var(--color-borde)',
                    color: aplicados.length ? 'var(--color-acento)' : undefined,
                }"
                @click="abierto = !abierto"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                Filtros
                <span
                    v-if="aplicados.length"
                    class="rounded-full px-1.5 text-xs"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                >
                    {{ aplicados.length }}
                </span>
            </button>

            <!-- Fichas de lo aplicado: visibles aunque el panel esté cerrado -->
            <span
                v-for="ficha in aplicados"
                :key="ficha.clave"
                class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm"
                style="background-color: color-mix(in srgb, currentColor 8%, transparent)"
            >
                <span :style="{ color: 'var(--color-suave)' }">{{ ficha.etiqueta }}:</span>
                <span>{{ ficha.texto }}</span>
                <button type="button" :aria-label="`Quitar filtro ${ficha.etiqueta}`" @click="quitar(ficha.clave)">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </span>

            <button
                v-if="aplicados.length > 1"
                type="button"
                class="text-sm"
                :style="{ color: 'var(--color-suave)' }"
                @click="limpiarTodos"
            >
                Limpiar todos
            </button>
        </div>

        <!-- Panel desplegable -->
        <div
            v-if="abierto"
            class="mt-3 rounded-xl border p-4"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="filtro in filtros" :key="filtro.clave">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            class="rounded"
                            :checked="activos[filtro.clave]"
                            @change="alternar(filtro)"
                        />
                        {{ filtro.etiqueta }}
                    </label>

                    <select
                        v-if="activos[filtro.clave]"
                        class="mt-2 w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        :value="valores[filtro.clave] ?? ''"
                        @change="elegir(filtro.clave, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Cualquiera</option>
                        <option v-for="opcion in filtro.opciones" :key="opcion.valor" :value="opcion.valor">
                            {{ opcion.texto }}
                        </option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</template>
