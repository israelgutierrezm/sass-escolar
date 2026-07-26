<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import SelectorVista from '@/Components/SelectorVista.vue';

/**
 * Barra de encabezado de un listado: buscador + vista + filtros + «Nuevo».
 *
 * Se hizo componente porque los listados de Académico (campus, carreras,
 * asignaturas, planes, oferta) necesitan exactamente lo mismo, y repetir el
 * input con su espera y el `router.get` en cada página es cinco veces el mismo
 * error esperando a que uno se corrija solo en un sitio.
 *
 * Orden fijo y homólogo en TODA la app: buscador, alternador lista/cuadrícula,
 * botón «Filtros» y botón «Nuevo». En una sola línea en pantallas grandes; en
 * móvil el buscador ocupa su propio renglón y los controles bajan debajo. Los
 * filtros abren en una fila aparte, con selectores más pequeños que los botones
 * para que no compitan visualmente con ellos.
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
        /**
         * Si se pasa, aparece el alternador lista/cuadrícula y recuerda la
         * preferencia con esta clave. Sin ella, el listado solo tiene tabla.
         */
        vistaClave?: string;
    }>(),
    { placeholder: 'Buscar…', puedeCrear: false, nuevoTexto: 'Nuevo' },
);

// Vista activa (lista/cuadrícula). El padre la usa con v-model:vista para
// decidir qué pinta; el <SelectorVista> persiste la preferencia por su cuenta.
const vista = defineModel<'lista' | 'cuadricula'>('vista', { default: 'lista' });

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
    <section class="tarjeta space-y-3 p-3 sm:p-4">
        <!-- Fila 1: buscador + vista + «Filtros» + «Nuevo». En una línea en
             pantallas grandes; en móvil el buscador ocupa su renglón y los
             controles bajan debajo, alineados a la derecha. -->
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <input
                v-model="busqueda"
                type="search"
                :placeholder="placeholder"
                class="w-full min-w-0 rounded-lg border px-3 py-2 text-sm sm:w-auto sm:min-w-52 sm:flex-1"
                :style="{ borderColor: 'var(--color-borde)' }"
            />

            <div class="flex w-full items-center gap-2 sm:w-auto">
                <SelectorVista v-if="vistaClave" v-model="vista" :clave="vistaClave" />

                <button
                    v-if="filtros.length"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium"
                    :style="{
                        borderColor: abierto || activos ? 'var(--color-acento)' : 'var(--color-borde)',
                        color: abierto || activos ? 'var(--color-acento)' : undefined,
                    }"
                    @click="abierto = !abierto"
                >
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                    </svg>
                    Filtros
                    <span v-if="activos" class="rounded-full px-1.5 text-xs" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        {{ activos }}
                    </span>
                </button>

                <BotonAccion
                    v-if="puedeCrear && nuevoHref"
                    variante="nuevo"
                    :texto="nuevoTexto"
                    :href="nuevoHref"
                    class="ms-auto sm:ms-0"
                />
            </div>
        </div>

        <!-- Fila 2: los filtros en su propia línea, DEBAJO del buscador, cuando
             se abren. Selectores más pequeños que los botones. En móvil crecen
             para llenar el ancho; en escritorio toman su tamaño natural. -->
        <div v-if="abierto" class="flex flex-wrap items-center gap-2 border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
            <select
                v-for="f in filtros"
                :key="f.clave"
                class="min-w-0 grow basis-40 rounded-lg border px-2.5 py-1.5 text-xs sm:grow-0 sm:basis-auto"
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

            <button
                v-if="activos"
                type="button"
                class="shrink-0 text-xs"
                :style="{ color: 'var(--color-suave)' }"
                @click="limpiarFiltros"
            >
                Limpiar
            </button>
        </div>
    </section>
</template>
