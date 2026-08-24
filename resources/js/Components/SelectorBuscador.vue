<script setup lang="ts">
/**
 * Selector con buscador: un <select> se vuelve inmanejable con cientos de
 * opciones (países), así que este abre un panel con un campo de búsqueda que
 * filtra por coincidencia (ignorando acentos y mayúsculas). Misma interfaz que
 * CampoSelect —{valor, texto}, v-model, etiqueta, error, ayuda— para poder
 * intercambiarlos sin fricción.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        etiqueta: string;
        opciones: { valor: string | number; texto: string }[];
        error?: string;
        requerido?: boolean;
        vacio?: string;
        ayuda?: string;
        deshabilitado?: boolean;
    }>(),
    { requerido: false },
);

const modelo = defineModel<string | number | null>();

const abierto = ref(false);
const busqueda = ref('');
const contenedor = ref<HTMLElement | null>(null);
const campoBusqueda = ref<HTMLInputElement | null>(null);

/** Normaliza para comparar sin acentos ni mayúsculas. */
function normal(texto: string): string {
    let salida = "";
    for (const ch of texto.normalize("NFD")) {
        const c = ch.charCodeAt(0);
        if (c < 0x0300 || c > 0x036f) salida += ch;
    }
    return salida.toLowerCase();
}

const textoSeleccionado = computed(
    () => props.opciones.find((o) => o.valor === modelo.value)?.texto ?? '',
);

const filtradas = computed(() => {
    const q = normal(busqueda.value.trim());
    if (q === '') {
        return props.opciones;
    }
    return props.opciones.filter((o) => normal(o.texto).includes(q));
});

async function abrir(): Promise<void> {
    if (props.deshabilitado) {
        return;
    }
    abierto.value = true;
    busqueda.value = '';
    await nextTick();
    campoBusqueda.value?.focus();
}

function cerrar(): void {
    abierto.value = false;
}

function elegir(valor: string | number | null): void {
    modelo.value = valor;
    cerrar();
}

function alClicFuera(evento: MouseEvent): void {
    if (contenedor.value && !contenedor.value.contains(evento.target as Node)) {
        cerrar();
    }
}

onMounted(() => document.addEventListener('mousedown', alClicFuera));
onBeforeUnmount(() => document.removeEventListener('mousedown', alClicFuera));
</script>

<template>
    <div ref="contenedor" class="relative">
        <label class="mb-1 block text-sm font-medium text-contenido">
            {{ etiqueta }}<span v-if="requerido" class="text-red-500"> *</span>
        </label>

        <button
            type="button"
            :disabled="deshabilitado"
            class="flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm focus:outline-none focus:ring-1 disabled:cursor-not-allowed disabled:opacity-60"
            :class="
                error
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
                    : 'border-borde foco-acento'
            "
            @click="abierto ? cerrar() : abrir()"
        >
            <span :class="textoSeleccionado ? 'text-contenido' : 'text-suave'">
                {{ textoSeleccionado || vacio || 'Selecciona…' }}
            </span>
            <svg class="h-4 w-4 shrink-0 text-suave" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <!-- Panel: buscador + lista filtrada. -->
        <div
            v-if="abierto"
            class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border bg-superficie shadow-lg"
            style="border-color: #cbd5e1"
        >
            <div class="border-b p-2" style="border-color: #e2e8f0">
                <input
                    ref="campoBusqueda"
                    v-model="busqueda"
                    type="text"
                    placeholder="Buscar…"
                    class="foco-acento w-full rounded-md border border-borde px-2 py-1.5 text-sm"
                    @keydown.esc="cerrar"
                />
            </div>

            <ul class="max-h-60 overflow-y-auto py-1 text-sm">
                <li v-if="vacio">
                    <button
                        type="button"
                        class="w-full px-3 py-1.5 text-left text-suave hover:bg-fondo"
                        @click="elegir(null)"
                    >
                        {{ vacio }}
                    </button>
                </li>
                <li v-for="opcion in filtradas" :key="opcion.valor">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-1.5 text-left hover:bg-fondo"
                        :class="opcion.valor === modelo ? 'font-medium texto-acento' : 'text-contenido'"
                        @click="elegir(opcion.valor)"
                    >
                        {{ opcion.texto }}
                        <svg v-if="opcion.valor === modelo" class="h-4 w-4 texto-acento" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </button>
                </li>
                <li v-if="!filtradas.length" class="px-3 py-3 text-center text-xs text-suave">
                    Sin coincidencias.
                </li>
            </ul>
        </div>

        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="ayuda" class="mt-1 text-xs text-suave">{{ ayuda }}</p>
    </div>
</template>
