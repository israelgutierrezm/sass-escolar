<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BotonAccion from '@/Components/BotonAccion.vue';

/**
 * Barra de encabezado de un listado: buscador + filtros + botón «Nuevo».
 *
 * Se hizo componente porque los listados de Académico (campus, carreras,
 * asignaturas, planes, oferta) necesitan exactamente lo mismo, y repetir el
 * input con su espera y el `router.get` en cada página es cinco veces el mismo
 * error esperando a que uno se corrija solo en un sitio.
 *
 * Los filtros van EN LÍNEA, no en un panel desplegable: al pulsar «Filtros» el
 * botón sube a una fila propia y los selectores aparecen en hilera junto al
 * buscador, ocupando un solo renglón. Amontonarlos debajo se veía apretado.
 */
interface DefinicionFiltro {
    clave: string;
    etiqueta: string;
    opciones: { valor: number | string; texto: string }[];
}

const props = withDefaults(
    defineProps<{
        /** Ruta del index al que se consulta (p. ej. /academico/campus). */
        url: string;
        /** Valores actuales, incluida `busqueda`. */
        valores: Record<string, any>;
        /** Definición de los filtros. */
        filtros: DefinicionFiltro[];
        placeholder?: string;
        nuevoHref?: string;
        nuevoTexto?: string;
        puedeCrear?: boolean;
    }>(),
    { placeholder: 'Buscar…', puedeCrear: false, nuevoTexto: 'Nuevo' },
);

const busqueda = ref(props.valores.busqueda ?? '');

// Cuántos filtros están aplicados ahora mismo (para el contador del botón).
const activos = computed(() => props.filtros.filter((f) => {
    const v = props.valores[f.clave];

    return v !== null && v !== '' && v !== undefined;
}).length);

// El panel arranca abierto si ya hay algún filtro puesto: si no, uno no
// entendería por qué la lista viene acotada.
const abierto = ref(activos.value > 0);

let temporizador: ReturnType<typeof setTimeout> | undefined;

// La búsqueda espera a que dejes de teclear; sin la pausa, cada tecla consulta.
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => consultar({ busqueda: busqueda.value }), 350);
});

function consultar(cambios: Record<string, any>): void {
    const params = { ...props.valores, busqueda: busqueda.value, ...cambios };

    // Los vacíos no viajan: ensucian la URL y no filtran nada.
    const limpio = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== '' && v !== undefined),
    );

    router.get(props.url, limpio, { preserveState: true, replace: true, preserveScroll: true });
}

function elegir(clave: string, valor: string): void {
    consultar({ [clave]: valor === '' ? null : valor });
}

function limpiarFiltros(): void {
    consultar(Object.fromEntries(props.filtros.map((f) => [f.clave, null])));
}
</script>

<template>
    <section class="tarjeta space-y-3 p-4">
        <!-- Al abrir, el botón «Filtros» sube a su propia fila para dejar la de
             abajo a los selectores. -->
        <div v-if="abierto" class="flex items-center gap-3">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium"
                :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                @click="abierto = false"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                Filtros
                <span v-if="activos" class="rounded-full px-1.5 text-xs" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                    {{ activos }}
                </span>
            </button>

            <button
                v-if="activos"
                type="button"
                class="text-sm"
                :style="{ color: 'var(--color-suave)' }"
                @click="limpiarFiltros"
            >
                Limpiar
            </button>
        </div>

        <!-- Fila principal: buscador + (si está abierto) los filtros en hilera. -->
        <div class="flex flex-wrap items-center gap-3">
            <input
                v-model="busqueda"
                type="search"
                :placeholder="placeholder"
                class="min-w-56 flex-1 rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            />

            <template v-if="abierto">
                <select
                    v-for="f in filtros"
                    :key="f.clave"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{
                        borderColor: valores[f.clave] ? 'var(--color-acento)' : 'var(--color-borde)',
                        color: valores[f.clave] ? 'var(--color-acento)' : undefined,
                    }"
                    :value="valores[f.clave] ?? ''"
                    @change="elegir(f.clave, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ f.etiqueta }}: todos</option>
                    <option v-for="o in f.opciones" :key="o.valor" :value="o.valor">{{ o.texto }}</option>
                </select>
            </template>

            <!-- Cerrado: el botón «Filtros» vive aquí, junto al buscador. -->
            <button
                v-else-if="filtros.length"
                type="button"
                class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium"
                :style="{
                    borderColor: activos ? 'var(--color-acento)' : 'var(--color-borde)',
                    color: activos ? 'var(--color-acento)' : undefined,
                }"
                @click="abierto = true"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                Filtros
                <span v-if="activos" class="rounded-full px-1.5 text-xs" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                    {{ activos }}
                </span>
            </button>

            <BotonAccion v-if="puedeCrear && nuevoHref" variante="nuevo" :texto="nuevoTexto" :href="nuevoHref" />
        </div>
    </section>
</template>
