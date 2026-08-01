<script setup lang="ts">
import axios from 'axios';
import { ref, watch } from 'vue';

/**
 * Buscador que consulta al SERVIDOR conforme se escribe.
 *
 * Distinto de `CampoBuscador`, que filtra en el navegador una lista que ya
 * viajó completa: eso sirve para cincuenta docentes y es inviable para los
 * alumnos de una escuela. Con mil matriculados, mandarlos todos en cada carga
 * de pantalla es caro y encontrarlos en un desplegable es imposible; aquí solo
 * viajan las coincidencias de lo que se teclea.
 *
 * Se escribe libre y se elige de las coincidencias: no hay que saber la
 * matrícula exacta ni recorrer una lista. Busca por matrícula o por cualquier
 * parte del nombre —el servidor decide—, con un respiro de 300 ms para no
 * disparar una consulta por tecla.
 */
interface Resultado {
    id: number;
    [clave: string]: unknown;
}

const props = withDefaults(
    defineProps<{
        /** Endpoint que responde con un arreglo de resultados; recibe `?q=`. */
        url: string;
        etiqueta: string;
        marcador?: string;
        error?: string;
        ayuda?: string;
        /** Mínimo de letras antes de consultar; menos que eso trae media escuela. */
        minimo?: number;
        /** Qué llave de cada resultado se pinta en cada renglón. */
        campos?: { titulo: string; subtitulo?: string; detalle?: string };
    }>(),
    {
        minimo: 2,
        campos: () => ({ titulo: 'nombre', subtitulo: 'matricula', detalle: 'carrera' }),
    },
);

const modelo = defineModel<number | null>();

const texto = ref('');
const resultados = ref<Resultado[]>([]);
const buscando = ref(false);
const elegido = ref<Resultado | null>(null);
let temporizador: ReturnType<typeof setTimeout> | undefined;

const valor = (r: Resultado, clave?: string) => (clave ? (r[clave] as string | null) : null);

watch(texto, (q) => {
    clearTimeout(temporizador);

    // Al volver a escribir se suelta lo elegido: si no, el formulario mandaría
    // un id que ya no corresponde a lo que se ve en el campo.
    if (elegido.value && q !== etiquetaDe(elegido.value)) {
        elegido.value = null;
        modelo.value = null;
    }

    if (q.trim().length < props.minimo) {
        resultados.value = [];

        return;
    }

    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(props.url, { params: { q } });
            resultados.value = data;
        } finally {
            buscando.value = false;
        }
    }, 300);
});

function etiquetaDe(r: Resultado): string {
    return [valor(r, props.campos.subtitulo), valor(r, props.campos.titulo)].filter(Boolean).join(' · ');
}

function elegir(r: Resultado): void {
    elegido.value = r;
    modelo.value = r.id;
    resultados.value = [];
    texto.value = etiquetaDe(r);
}

function limpiar(): void {
    elegido.value = null;
    modelo.value = null;
    texto.value = '';
    resultados.value = [];
}

defineExpose({ limpiar });
</script>

<template>
    <div class="relative">
        <label class="mb-1 block text-sm font-medium text-contenido">{{ etiqueta }}</label>

        <div class="relative">
            <input
                v-model="texto"
                type="search"
                :placeholder="marcador"
                class="w-full rounded-lg border px-3 py-2 pr-8 text-sm focus:outline-none focus:ring-1"
                :class="error
                    ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
                    : 'border-borde focus:border-indigo-500 focus:ring-indigo-500'"
            />
            <button
                v-if="texto"
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-suave hover:text-contenido"
                aria-label="Limpiar"
                @click="limpiar"
            >
                ×
            </button>
        </div>

        <ul
            v-if="resultados.length"
            class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border shadow-lg"
            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }"
        >
            <li v-for="r in resultados" :key="r.id">
                <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-black/5" @click="elegir(r)">
                    <span class="font-medium">{{ valor(r, campos.titulo) }}</span>
                    <span v-if="campos.subtitulo" class="ml-2 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ valor(r, campos.subtitulo) }}
                    </span>
                    <span v-if="campos.detalle && valor(r, campos.detalle)" class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ valor(r, campos.detalle) }}
                    </span>
                </button>
            </li>
        </ul>

        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        <p v-else-if="buscando" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">Buscando…</p>
        <p
            v-else-if="texto.trim().length >= minimo && !resultados.length && !elegido"
            class="mt-1 text-xs"
            :style="{ color: 'var(--color-suave)' }"
        >
            Nadie coincide con «{{ texto }}».
        </p>
        <p v-else-if="ayuda" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ ayuda }}</p>
    </div>
</template>
