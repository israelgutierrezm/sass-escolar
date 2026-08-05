<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import SelectorVista from '@/Components/SelectorVista.vue';

/**
 * Barra de encabezado de un listado: buscador + vista + filtros + «Nuevo».
 *
 * Se hizo componente porque los listados (académico, escolar, finanzas…)
 * necesitan exactamente lo mismo, y repetir el input con su espera y el
 * `router.get` en cada página es el mismo error copiado N veces esperando a que
 * uno se corrija solo en un sitio.
 *
 * Orden fijo y homólogo en TODA la app: buscador, alternador lista/cuadrícula,
 * botón «Filtros» y botón «Nuevo». En una sola línea en pantallas grandes; en
 * móvil el buscador ocupa su propio renglón y los controles bajan debajo. Los
 * filtros abren en una fila aparte, con controles más pequeños que los botones
 * para que no compitan visualmente con ellos.
 *
 * Un filtro puede ser un desplegable (`opciones`) o un interruptor sí/no
 * (`tipo: 'booleano'`), que viaja como `1` cuando está activo. El buscador se
 * puede ocultar (`sin-buscador`) y su parámetro de URL es configurable
 * (`clave-busqueda`), porque no todos los listados lo llaman «busqueda».
 */
interface DefinicionFiltro {
    clave: string;
    etiqueta: string;
    tipo?: 'select' | 'booleano';
    opciones?: { valor: number | string; texto: string }[];
}

const props = withDefaults(
    defineProps<{
        /** Ruta del index al que se consulta (p. ej. /academico/campus). */
        url: string;
        /** Valores actuales, incluida la búsqueda. */
        valores: Record<string, any>;
        /** Definición de los filtros. */
        filtros: DefinicionFiltro[];
        placeholder?: string;
        nuevoHref?: string;
        nuevoTexto?: string;
        puedeCrear?: boolean;
        /** Oculta el buscador (listados que solo filtran). */
        sinBuscador?: boolean;
        /** Nombre del parámetro de búsqueda en la URL. */
        claveBusqueda?: string;
        /**
         * Si se pasa, aparece el alternador lista/cuadrícula y recuerda la
         * preferencia con esta clave. Sin ella, el listado solo tiene tabla.
         */
        vistaClave?: string;
        /**
         * Encabezado opcional del listado (ícono + título + descripción) DENTRO
         * de la barra, para que sea el encabezado de todo y abajo solo la tabla.
         * Si se omiten, la barra se ve como siempre (los demás listados no cambian).
         */
        titulo?: string;
        descripcion?: string;
        /** `d` de un <path> SVG (heroicons, viewBox 0 0 24 24, trazo). */
        icono?: string;
    }>(),
    { placeholder: 'Buscar…', puedeCrear: false, nuevoTexto: 'Nuevo', sinBuscador: false, claveBusqueda: 'busqueda' },
);

/** Se emite cuando «Nuevo» no es un enlace sino una acción de la pantalla. */
const emit = defineEmits<{ nuevo: [] }>();

// Vista activa (lista/cuadrícula). El padre la usa con v-model:vista para
// decidir qué pinta; el <SelectorVista> persiste la preferencia por su cuenta.
const vista = defineModel<'lista' | 'cuadricula'>('vista', { default: 'lista' });

const busqueda = ref(props.valores[props.claveBusqueda] ?? '');

// Cuántos filtros están aplicados ahora mismo (para el contador del botón).
const activos = computed(() => props.filtros.filter((f) => {
    const v = props.valores[f.clave];

    return v !== null && v !== '' && v !== undefined && v !== false;
}).length);

// El panel arranca abierto si ya hay algún filtro puesto: si no, uno no
// entendería por qué la lista viene acotada.
const abierto = ref(activos.value > 0);

let temporizador: ReturnType<typeof setTimeout> | undefined;

// La búsqueda espera a que dejes de teclear; sin la pausa, cada tecla consulta.
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => consultar({ [props.claveBusqueda]: busqueda.value }), 350);
});

function consultar(cambios: Record<string, any>): void {
    const params = { ...props.valores, [props.claveBusqueda]: busqueda.value, ...cambios };

    // Los vacíos no viajan: ensucian la URL y no filtran nada.
    const limpio = Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== null && v !== '' && v !== undefined && v !== false),
    );

    router.get(props.url, limpio, { preserveState: true, replace: true, preserveScroll: true });
}

function elegir(clave: string, valor: string): void {
    consultar({ [clave]: valor === '' ? null : valor });
}

function alternarBooleano(clave: string): void {
    consultar({ [clave]: props.valores[clave] ? null : 1 });
}

function limpiarFiltros(): void {
    consultar(Object.fromEntries(props.filtros.map((f) => [f.clave, null])));
}
</script>

<template>
    <section class="tarjeta space-y-3 p-3 sm:p-4">
        <!-- Encabezado opcional del listado (ícono + título + descripción):
             cuando se pasa, la barra es el encabezado de todo. -->
        <div
            v-if="titulo || $slots.conteo"
            class="flex items-center justify-between gap-3 border-b pb-3"
            :style="{ borderColor: 'var(--color-borde)' }"
        >
            <div class="flex items-center gap-3">
                <span
                    v-if="icono"
                    class="grid h-9 w-9 shrink-0 place-items-center rounded-full"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icono" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <h2 v-if="titulo" class="text-sm font-semibold text-contenido">{{ titulo }}</h2>
                    <p v-if="descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ descripcion }}</p>
                </div>
            </div>
            <div v-if="$slots.conteo" class="shrink-0">
                <slot name="conteo" />
            </div>
        </div>

        <!-- Fila 1: buscador + vista + «Filtros» + «Nuevo». En una línea en
             pantallas grandes; en móvil el buscador ocupa su renglón y los
             controles bajan debajo, alineados a la derecha. -->
        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
            <!-- «Filtros» al INICIO de la fila, alineado con los filtros que abren
                 debajo (que empiezan del lado izquierdo). -->
            <button
                v-if="filtros.length"
                type="button"
                class="anim-crecer inline-flex shrink-0 items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium"
                :class="activos ? 'filtro-activo' : ''"
                :style="{
                    borderColor: abierto || activos ? 'var(--color-acento)' : 'var(--color-borde)',
                    color: abierto || activos ? 'var(--color-acento)' : undefined,
                }"
                @click="abierto = !abierto"
            >
                <svg class="icono-anim h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                Filtros
                <span v-if="activos" class="rounded-full px-1.5 text-xs" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                    {{ activos }}
                </span>
            </button>

            <input
                v-if="!sinBuscador"
                v-model="busqueda"
                type="search"
                :placeholder="placeholder"
                class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm sm:min-w-52"
                :style="{ borderColor: 'var(--color-borde)' }"
            />

            <div class="ms-auto flex items-center gap-2">
                <SelectorVista v-if="vistaClave" v-model="vista" :clave="vistaClave" />

                <!--
                    «Nuevo» lleva a otra pantalla o abre un panel aquí mismo.
                    Los catálogos chicos —conceptos, descuentos, becas— capturan
                    en la misma página para no perder de vista lo que ya hay, y
                    antes tenían que sacar su botón fuera de la barra: quedaba en
                    otro sitio que en el resto de la app.
                -->
                <BotonAccion
                    v-if="puedeCrear && nuevoHref"
                    variante="nuevo"
                    :texto="nuevoTexto"
                    :href="nuevoHref"
                />
                <BotonAccion
                    v-else-if="puedeCrear"
                    variante="nuevo"
                    :texto="nuevoTexto"
                    @click="emit('nuevo')"
                />
            </div>
        </div>

        <!-- Fila 2: los filtros en su propia línea, DEBAJO del buscador, cuando
             se abren. Controles más pequeños que los botones. En móvil crecen
             para llenar el ancho; en escritorio toman su tamaño natural. -->
        <div v-if="abierto" class="flex flex-wrap items-center gap-2 border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
            <template v-for="f in filtros" :key="f.clave">
                <!-- Interruptor sí/no -->
                <button
                    v-if="f.tipo === 'booleano'"
                    type="button"
                    class="shrink-0 rounded-lg border px-2.5 py-1.5 text-xs font-medium"
                    :style="{
                        borderColor: valores[f.clave] ? 'var(--color-acento)' : 'var(--color-borde)',
                        color: valores[f.clave] ? 'var(--color-acento)' : 'var(--color-suave)',
                        backgroundColor: valores[f.clave] ? 'color-mix(in srgb, var(--color-acento) 10%, transparent)' : undefined,
                    }"
                    @click="alternarBooleano(f.clave)"
                >
                    {{ f.etiqueta }}
                </button>

                <!-- Desplegable -->
                <select
                    v-else
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
            </template>

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
