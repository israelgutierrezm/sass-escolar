<script setup lang="ts">
import { computed, ref } from 'vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';

/**
 * A quién le llega un evento del calendario.
 *
 * ── El problema que resuelve ───────────────────────────────────────────────
 * «A todos», «a los docentes», «al campus norte», «a estos tres alumnos». Son
 * criterios de naturaleza distinta —uno no lleva id, otro sale de un catálogo
 * corto, otro de una búsqueda entre miles— y meterlos en un solo desplegable
 * los volvería inservibles.
 *
 * Aquí se elige primero QUÉ criterio y luego CUÁL, y lo elegido se acumula como
 * fichas. Los destinos se SUMAN: quien encaje en cualquiera de ellos ve el
 * evento.
 */
interface Destino {
    tipo: string;
    destino_id: number | null;
}

interface Opcion {
    id: number;
    nombre: string;
}

const props = defineProps<{
    /** Los criterios disponibles, del enum del servidor. */
    tipos: { valor: string; etiqueta: string; necesita_id: boolean }[];
    /** Catálogos por criterio: rol, campus, nivel… */
    opciones: Record<string, Opcion[]>;
    /** Endpoint del buscador de alumnos. */
    urlAlumnos: string;
    error?: string;
}>();

const modelo = defineModel<Destino[]>({ required: true });

const criterio = ref<string>('todos');
const elegido = ref<number | null>(null);
const alumnoElegido = ref<number | null>(null);
const alumnoNombre = ref<string>('');

const criterioActual = computed(() => props.tipos.find((t) => t.valor === criterio.value));

const catalogoActual = computed<Opcion[]>(() => props.opciones[criterio.value] ?? []);

/** Cómo se llama cada ficha ya puesta. */
function nombreDe(destino: Destino): string {
    const tipo = props.tipos.find((t) => t.valor === destino.tipo);

    if (destino.destino_id === null) return tipo?.etiqueta ?? destino.tipo;

    // El nombre viene con el destino cuando se está editando un evento
    // guardado; si no, se busca en el catálogo que ya tenemos cargado.
    const guardado = (destino as Destino & { nombre?: string }).nombre;

    if (guardado) return guardado;

    const opcion = (props.opciones[destino.tipo] ?? []).find((o) => o.id === destino.destino_id);

    return opcion ? `${tipo?.etiqueta}: ${opcion.nombre}` : `${tipo?.etiqueta} #${destino.destino_id}`;
}

const yaEsta = (tipo: string, id: number | null): boolean =>
    modelo.value.some((d) => d.tipo === tipo && d.destino_id === id);

function agregar(): void {
    const tipo = criterio.value;

    if (!criterioActual.value?.necesita_id) {
        // «Toda la escuela» hace redundante a cualquier otro destino: si llega
        // a todos, señalar además un grupo no cambia nada y sí confunde.
        if (!yaEsta(tipo, null)) modelo.value = [{ tipo, destino_id: null }];

        return;
    }

    const id = tipo === 'alumno' ? alumnoElegido.value : elegido.value;

    if (id === null) return;

    if (!yaEsta(tipo, id)) {
        const nombre = tipo === 'alumno'
            ? `Alumno: ${alumnoNombre.value}`
            : `${criterioActual.value.etiqueta}: ${catalogoActual.value.find((o) => o.id === id)?.nombre ?? id}`;

        modelo.value = [...modelo.value, { tipo, destino_id: id, nombre } as Destino];
    }

    elegido.value = null;
    alumnoElegido.value = null;
    alumnoNombre.value = '';
}

function quitar(indice: number): void {
    const quedan = [...modelo.value];

    quedan.splice(indice, 1);
    modelo.value = quedan;
}

/** El buscador devuelve la persona completa; se guarda el nombre para la ficha. */
function alElegirAlumno(resultado: Record<string, unknown> | null): void {
    alumnoNombre.value = (resultado?.nombre as string) ?? '';
}

const alcanzaATodos = computed(() => modelo.value.some((d) => d.tipo === 'todos'));
</script>

<template>
    <div>
        <label class="mb-1 block text-sm font-medium">¿A quién le llega?</label>

        <div class="rounded-lg border p-3" :style="{ borderColor: 'var(--color-borde)' }">
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-40 flex-1">
                    <label class="mb-1 block text-xs text-suave">Criterio</label>
                    <select
                        v-model="criterio"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @change="elegido = null; alumnoElegido = null"
                    >
                        <option v-for="t in tipos" :key="t.valor" :value="t.valor">{{ t.etiqueta }}</option>
                    </select>
                </div>

                <!-- Los alumnos se buscan; los demás catálogos caben en una lista. -->
                <div v-if="criterio === 'alumno'" class="min-w-56 flex-[2]">
                    <BuscadorRemoto
                        v-model="alumnoElegido"
                        :url="urlAlumnos"
                        etiqueta="Alumno"
                        marcador="Nombre o matrícula…"
                        @elegido="alElegirAlumno"
                    />
                </div>

                <div v-else-if="criterioActual?.necesita_id" class="min-w-56 flex-[2]">
                    <label class="mb-1 block text-xs text-suave">{{ criterioActual.etiqueta }}</label>
                    <select
                        v-model="elegido"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option :value="null">Elige…</option>
                        <option v-for="o in catalogoActual" :key="o.id" :value="o.id">{{ o.nombre }}</option>
                    </select>
                </div>

                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="agregar"
                >
                    Agregar
                </button>
            </div>

            <p v-if="alcanzaATodos" class="mt-2 text-xs text-suave">
                Al llegar a toda la escuela, cualquier otro destino sobra.
            </p>

            <!-- Lo elegido, como fichas: se ve de un vistazo a quién alcanza. -->
            <ul v-if="modelo.length" class="mt-3 flex flex-wrap gap-2">
                <li
                    v-for="(d, i) in modelo"
                    :key="`${d.tipo}-${d.destino_id}-${i}`"
                    class="inline-flex items-center gap-1.5 rounded-full py-1 pl-3 pr-1.5 text-xs"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                >
                    {{ nombreDe(d) }}
                    <button
                        type="button"
                        class="grid h-4 w-4 place-items-center rounded-full transition hover:bg-[color-mix(in_srgb,var(--color-acento)_25%,transparent)]"
                        :title="`Quitar ${nombreDe(d)}`"
                        @click="quitar(i)"
                    >
                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </li>
            </ul>

            <p v-else class="mt-3 text-xs" :style="{ color: '#d97706' }">
                Todavía no le llega a nadie. Agrega al menos un destinatario.
            </p>
        </div>

        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
