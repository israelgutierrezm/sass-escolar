<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import PanelFiltros from '@/Components/PanelFiltros.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

/**
 * Barra de encabezado de un listado: buscador + filtros + botón «Nuevo».
 *
 * Se hizo componente porque los listados de Académico (campus, carreras,
 * asignaturas, planes, oferta) necesitan exactamente lo mismo, y repetir el
 * input con su espera, el `PanelFiltros` y el `router.get` en cada página es
 * cinco veces el mismo error esperando a que uno se corrija solo en un sitio.
 * Aquí vive una vez: la barra navega sola al escribir o al cambiar un filtro.
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
        /** Definición de los filtros para el PanelFiltros. */
        filtros: DefinicionFiltro[];
        placeholder?: string;
        nuevoHref?: string;
        nuevoTexto?: string;
        puedeCrear?: boolean;
    }>(),
    { placeholder: 'Buscar…', puedeCrear: false, nuevoTexto: 'Nuevo' },
);

const busqueda = ref(props.valores.busqueda ?? '');

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
</script>

<template>
    <section class="tarjeta p-4">
        <div class="flex flex-wrap items-center gap-3">
            <input
                v-model="busqueda"
                type="search"
                :placeholder="placeholder"
                class="min-w-64 flex-1 rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            />

            <PanelFiltros v-if="filtros.length" :filtros="filtros" :valores="valores" @cambio="(v) => consultar(v)" />

            <BotonAccion v-if="puedeCrear && nuevoHref" variante="nuevo" :texto="nuevoTexto" :href="nuevoHref" />
        </div>
    </section>
</template>
