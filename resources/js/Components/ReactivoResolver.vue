<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';

/*
 * Un reactivo, con la forma de responderlo que le toque.
 *
 * Vive aparte de la pantalla de resolver porque son doce formas distintas: en
 * un solo archivo, el examen sería un `v-if` de trescientas líneas donde nadie
 * encuentra el caso que falla. Aquí cada forma es un bloque corto y aislado.
 *
 * El componente NO sabe nada de la respuesta correcta —el servidor no se la
 * manda— y no califica: solo recoge y avisa hacia arriba.
 */
interface Opcion {
    id: number;
    texto: string;
}

interface Reactivo {
    id: number;
    tipo: string;
    forma: string;
    enunciado: string;
    imagen: string | null;
    opciones: Opcion[];
    categorias: string[];
    huecos: number;
    puntos: number;
    respuesta: any;
    /** Solo en los de archivo: para poder volver a descargar lo que subió. */
    respuesta_id?: number | null;
}

const props = defineProps<{ reactivo: Reactivo; numero: number; guardando: boolean }>();
const emit = defineEmits<{ (e: 'responder', valor: any): void }>();

/*
 * El valor vive aquí y se avisa hacia arriba en cada cambio. Arriba se encarga
 * de mandarlo al servidor con retardo: guardar en cada tecla escrita sería una
 * petición por letra.
 */
const valor = ref<any>(props.reactivo.respuesta ?? valorInicial());

function valorInicial(): any {
    switch (props.reactivo.forma) {
        case 'varias_opciones':
            return [];
        case 'huecos':
            return Array.from({ length: props.reactivo.huecos }, () => '');
        case 'emparejar':
            return {};
        case 'ordenar':
            return props.reactivo.opciones.map((o) => o.id);
        default:
            return null;
    }
}

watch(
    () => props.reactivo.id,
    () => {
        valor.value = props.reactivo.respuesta ?? valorInicial();
    },
);

/*
 * Ordenar arranca ya contestado. La secuencia que se le presenta ES una
 * respuesta posible —barajada, pero válida—, así que se guarda al montar en vez
 * de esperar a que mueva algo. Si no, un alumno que la ve bien acomodada por
 * casualidad la deja quieta, la entrega sin fila de respuesta y saca cero por
 * una pregunta que en pantalla se veía contestada.
 */
onMounted(() => {
    if (props.reactivo.forma === 'ordenar' && props.reactivo.respuesta === null) {
        avisar();
    }
});

function avisar(): void {
    emit('responder', valor.value);
}

/** Marca o desmarca una opción en los de varias respuestas. */
function alternar(id: number): void {
    const actual: number[] = Array.isArray(valor.value) ? [...valor.value] : [];
    const i = actual.indexOf(id);

    i === -1 ? actual.push(id) : actual.splice(i, 1);
    valor.value = actual;
    avisar();
}

function marcada(id: number): boolean {
    return Array.isArray(valor.value) && valor.value.includes(id);
}

/** Sube o baja un elemento en los de ordenar. */
function mover(indice: number, direccion: -1 | 1): void {
    const destino = indice + direccion;
    const lista: number[] = [...(valor.value ?? [])];

    if (destino < 0 || destino >= lista.length) return;

    [lista[indice], lista[destino]] = [lista[destino], lista[indice]];
    valor.value = lista;
    avisar();
}

const ordenadas = computed<Opcion[]>(() => {
    const ids: number[] = valor.value ?? [];

    return ids
        .map((id) => props.reactivo.opciones.find((o) => o.id === id))
        .filter((o): o is Opcion => o !== undefined);
});

/** Click sobre la imagen: se guarda normalizado, no en píxeles. */
function señalar(evento: MouseEvent): void {
    const caja = (evento.currentTarget as HTMLElement).getBoundingClientRect();

    valor.value = {
        x: Number(((evento.clientX - caja.left) / caja.width).toFixed(4)),
        y: Number(((evento.clientY - caja.top) / caja.height).toFixed(4)),
    };
    avisar();
}

function subirArchivo(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (archivo) emit('responder', archivo);
}

/** Si ya hay algo que mandar; sirve para el indicador de contestada. */
const contestado = computed(() => {
    const v = valor.value;

    if (v === null || v === undefined || v === '') return false;
    if (Array.isArray(v)) return v.length > 0 && v.some((x) => x !== '');
    if (typeof v === 'object') return Object.keys(v).length > 0;

    return true;
});

defineExpose({ contestado });
</script>

<template>
    <article class="tarjeta p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <h3 class="min-w-0 flex-1 text-sm font-semibold text-contenido">
                <span class="mr-2 text-suave">{{ numero }}.</span>{{ reactivo.enunciado }}
            </h3>
            <span class="shrink-0 text-xs text-suave">{{ reactivo.puntos }} pt</span>
        </header>

        <img
            v-if="reactivo.imagen && reactivo.forma !== 'coordenada'"
            :src="reactivo.imagen"
            alt=""
            class="mt-4 max-h-80 rounded-lg border border-borde"
        />

        <div class="mt-4">
            <!-- Una sola respuesta -->
            <div v-if="reactivo.forma === 'una_opcion'" class="space-y-2">
                <label
                    v-for="o in reactivo.opciones"
                    :key="o.id"
                    class="flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 text-sm transition"
                    :style="{
                        borderColor: valor === o.id ? 'var(--color-acento)' : 'var(--color-borde)',
                        backgroundColor: valor === o.id ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)' : 'transparent',
                    }"
                >
                    <input
                        v-model="valor"
                        type="radio"
                        :value="o.id"
                        :name="`r${reactivo.id}`"
                        class="mt-0.5"
                        @change="avisar"
                    />
                    <span>{{ o.texto }}</span>
                </label>
            </div>

            <!-- Varias respuestas -->
            <div v-else-if="reactivo.forma === 'varias_opciones'" class="space-y-2">
                <p class="mb-2 text-xs text-suave">Marca todas las que correspondan.</p>
                <label
                    v-for="o in reactivo.opciones"
                    :key="o.id"
                    class="flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2 text-sm transition"
                    :style="{
                        borderColor: marcada(o.id) ? 'var(--color-acento)' : 'var(--color-borde)',
                        backgroundColor: marcada(o.id) ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)' : 'transparent',
                    }"
                >
                    <input type="checkbox" :checked="marcada(o.id)" class="mt-0.5" @change="alternar(o.id)" />
                    <span>{{ o.texto }}</span>
                </label>
            </div>

            <!-- Redacción -->
            <textarea
                v-else-if="reactivo.forma === 'texto_largo'"
                v-model="valor"
                rows="6"
                class="w-full rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                placeholder="Escribe tu respuesta."
                @blur="avisar"
            />

            <!-- Palabra o cifra -->
            <input
                v-else-if="reactivo.forma === 'texto_corto'"
                v-model="valor"
                type="text"
                class="w-full max-w-md rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @blur="avisar"
            />

            <input
                v-else-if="reactivo.forma === 'numero'"
                v-model="valor"
                type="number"
                step="any"
                class="w-40 rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @blur="avisar"
            />

            <!-- Completar espacios -->
            <div v-else-if="reactivo.forma === 'huecos'" class="space-y-2">
                <div v-for="i in reactivo.huecos" :key="i" class="flex items-center gap-3">
                    <span class="w-16 shrink-0 text-xs text-suave">Hueco {{ i }}</span>
                    <input
                        v-model="valor[i - 1]"
                        type="text"
                        class="w-full max-w-sm rounded-lg border px-3 py-1.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @blur="avisar"
                    />
                </div>
            </div>

            <!-- Relacionar o clasificar -->
            <div v-else-if="reactivo.forma === 'emparejar'" class="space-y-2">
                <div
                    v-for="o in reactivo.opciones"
                    :key="o.id"
                    class="flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="min-w-0 flex-1 text-sm">{{ o.texto }}</span>
                    <select
                        v-model="valor[o.id]"
                        class="rounded-lg border px-2 py-1 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @change="avisar"
                    >
                        <option :value="undefined">— elige —</option>
                        <option v-for="c in reactivo.categorias" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
            </div>

            <!-- Ordenar -->
            <div v-else-if="reactivo.forma === 'ordenar'" class="space-y-2">
                <p class="mb-2 text-xs text-suave">Acomódalos en el orden correcto.</p>
                <div
                    v-for="(o, i) in ordenadas"
                    :key="o.id"
                    class="flex items-center gap-3 rounded-lg border px-3 py-2"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="w-5 shrink-0 text-center text-xs font-semibold text-suave">{{ i + 1 }}</span>
                    <span class="min-w-0 flex-1 text-sm">{{ o.texto }}</span>
                    <span class="flex shrink-0 gap-1">
                        <button
                            type="button"
                            class="rounded border px-2 py-0.5 text-xs disabled:opacity-30"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            :disabled="i === 0"
                            @click="mover(i, -1)"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            class="rounded border px-2 py-0.5 text-xs disabled:opacity-30"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            :disabled="i === ordenadas.length - 1"
                            @click="mover(i, 1)"
                        >
                            ↓
                        </button>
                    </span>
                </div>
            </div>

            <!-- Señalar en la imagen -->
            <div v-else-if="reactivo.forma === 'coordenada'">
                <p class="mb-2 text-xs text-suave">Haz clic sobre el punto correcto.</p>
                <div
                    v-if="reactivo.imagen"
                    class="relative inline-block cursor-crosshair overflow-hidden rounded-lg border border-borde"
                    @click="señalar"
                >
                    <img :src="reactivo.imagen" alt="" class="max-h-96 select-none" draggable="false" />
                    <span
                        v-if="valor?.x !== undefined"
                        class="pointer-events-none absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white"
                        :style="{
                            left: `${valor.x * 100}%`,
                            top: `${valor.y * 100}%`,
                            backgroundColor: 'var(--color-acento)',
                        }"
                    />
                </div>
                <p v-else class="text-sm text-suave">Este reactivo no tiene imagen cargada.</p>
            </div>

            <!-- Subir un archivo -->
            <div v-else-if="reactivo.forma === 'archivo'">
                <input type="file" class="text-sm" @change="subirArchivo" />
                <p v-if="valor?.nombre" class="mt-2 text-xs text-suave">
                    Subiste
                    <a
                        v-if="reactivo.respuesta_id"
                        :href="`/respuestas/${reactivo.respuesta_id}/archivo`"
                        class="font-medium underline"
                        :style="{ color: 'var(--color-acento)' }"
                    >{{ valor.nombre }}</a>
                    <strong v-else>{{ valor.nombre }}</strong>. Subir otro lo reemplaza.
                </p>
            </div>
        </div>

        <p v-if="guardando" class="mt-3 text-xs text-suave">Guardando…</p>
    </article>
</template>
