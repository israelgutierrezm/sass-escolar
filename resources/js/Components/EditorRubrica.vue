<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';

/**
 * Armar una rúbrica: sus criterios y, dentro de cada uno, sus niveles.
 *
 * ── Los puntos viven en el NIVEL y en ningún otro sitio ────────────────────
 * No hay campo «cuánto vale este criterio»: su máximo es el nivel más alto, y se
 * muestra calculado. Un campo aparte podría decir 10 con un nivel máximo de 8, y
 * entonces la pantalla tendría que elegir a cuál creerle.
 *
 * ── Dos niveles como mínimo ────────────────────────────────────────────────
 * Un criterio con uno solo da los mismos puntos pase lo que pase: no evalúa
 * nada. Lo impide el servidor y aquí se dice antes, para no descubrirlo al
 * guardar.
 *
 * ── Una rúbrica en uso se abre para MIRARLA ────────────────────────────────
 * En cuanto calificó a alguien, sus criterios se congelan: quitarle uno dejaría
 * las evaluaciones hechas sumando un total que ya no cuadra. Los campos se
 * deshabilitan y se ofrece duplicar, que es la forma de hacerle una versión
 * nueva sin tocar las notas que ya puso.
 */
interface Nivel {
    titulo: string;
    descripcion: string | null;
    puntos: number | string;
}

interface Criterio {
    titulo: string;
    descripcion: string | null;
    niveles: Nivel[];
}

export interface RubricaEditable {
    id: number;
    nombre: string;
    descripcion: string | null;
    ambito: string;
    activa: boolean;
    en_uso: boolean;
    criterios: { titulo: string; descripcion: string | null; niveles: Nivel[] }[];
}

const props = defineProps<{
    /** Null = una rúbrica nueva. */
    rubrica: RubricaEditable | null;
    puedo: { publicar: boolean; tenerPropias: boolean };
}>();

const emit = defineEmits<{ cerrar: [] }>();

/** Una rúbrica nueva nace con un criterio de tres niveles: la forma más común. */
function criterioEnBlanco(): Criterio {
    return {
        titulo: '',
        descripcion: null,
        niveles: [
            { titulo: 'Excelente', descripcion: null, puntos: 3 },
            { titulo: 'Suficiente', descripcion: null, puntos: 2 },
            { titulo: 'Insuficiente', descripcion: null, puntos: 0 },
        ],
    };
}

const esNueva = computed(() => props.rubrica === null);
/** Congelada: se puede leer y se puede renombrar, pero no reestructurar. */
const congelada = computed(() => props.rubrica?.en_uso ?? false);

const form = useForm({
    nombre: props.rubrica?.nombre ?? '',
    descripcion: props.rubrica?.descripcion ?? '',
    activa: props.rubrica?.activa ?? true,
    // Por omisión, la propia: publicar para toda la escuela es la decisión
    // grande y no debería ser la que sale marcada sin pensarlo.
    ambito: props.puedo.tenerPropias ? 'docente' : 'plataforma',
    criterios: (props.rubrica?.criterios ?? [criterioEnBlanco()]).map((c) => ({
        titulo: c.titulo,
        descripcion: c.descripcion,
        niveles: c.niveles.map((n) => ({ titulo: n.titulo, descripcion: n.descripcion, puntos: n.puntos })),
    })) as Criterio[],
});

/** El máximo de un criterio: su nivel más alto. */
function maximoDe(criterio: Criterio): number {
    return criterio.niveles.reduce((alto, n) => Math.max(alto, Number(n.puntos) || 0), 0);
}

const total = computed(() => form.criterios.reduce((suma, c) => suma + maximoDe(c), 0));

function agregarCriterio(): void {
    form.criterios.push(criterioEnBlanco());
}

function quitarCriterio(i: number): void {
    form.criterios.splice(i, 1);
}

function moverCriterio(i: number, salto: number): void {
    const destino = i + salto;

    if (destino < 0 || destino >= form.criterios.length) return;

    const [movido] = form.criterios.splice(i, 1);
    form.criterios.splice(destino, 0, movido);
}

function agregarNivel(criterio: Criterio): void {
    criterio.niveles.push({ titulo: '', descripcion: null, puntos: 0 });
}

function quitarNivel(criterio: Criterio, i: number): void {
    criterio.niveles.splice(i, 1);
}

/** Qué criterio tiene abierto el detalle de sus niveles. */
const abierto = ref<number | null>(0);

function alternar(i: number): void {
    abierto.value = abierto.value === i ? null : i;
}

/** El error que el servidor devuelve por un campo anidado. */
function errorDe(ruta: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[ruta];
}

function guardar(): void {
    if (esNueva.value) {
        form.post('/rubricas', { preserveScroll: true, onSuccess: () => emit('cerrar') });

        return;
    }

    form.put(`/rubricas/${props.rubrica!.id}`, { preserveScroll: true, onSuccess: () => emit('cerrar') });
}
</script>

<template>
    <form class="tarjeta p-5" @submit.prevent="guardar">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <CampoTexto
                    v-model="form.nombre"
                    etiqueta="Nombre"
                    requerido
                    marcador="Ensayo argumentativo"
                    :error="form.errors.nombre"
                />
            </div>
            <div class="flex items-end pb-1">
                <label class="flex items-center gap-2 text-sm text-contenido">
                    <input v-model="form.activa" type="checkbox" />
                    Se ofrece al crear actividades
                </label>
            </div>
            <div class="sm:col-span-3">
                <CampoTextarea
                    v-model="form.descripcion"
                    etiqueta="Para qué sirve"
                    :filas="2"
                    ayuda="Lo lee quien la elija al armar una actividad. «Para trabajos escritos de 2 a 4 cuartillas.»"
                    :error="form.errors.descripcion"
                />
            </div>
        </div>

        <!-- Dónde se guarda. Sólo al crearla: cambiarlo después sería publicar
             (o despublicar) algo que ya puede estar en uso, y eso se hace
             duplicando, que deja el original donde estaba. -->
        <div v-if="esNueva && puedo.publicar && puedo.tenerPropias" class="mt-4">
            <span class="block text-xs font-medium text-suave">De quién es</span>
            <div class="mt-1.5 flex flex-wrap gap-2">
                <label
                    v-for="opcion in [
                        { valor: 'docente', titulo: 'Mía', detalle: 'Sólo yo la veo y la uso.' },
                        { valor: 'plataforma', titulo: 'De la escuela', detalle: 'La ve y la usa todo el mundo.' },
                    ]"
                    :key="opcion.valor"
                    class="flex-1 cursor-pointer rounded-lg border px-3 py-2 text-sm transition"
                    :style="{
                        borderColor: form.ambito === opcion.valor ? 'var(--color-acento)' : 'var(--color-borde)',
                        backgroundColor: form.ambito === opcion.valor
                            ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)'
                            : 'transparent',
                    }"
                >
                    <input v-model="form.ambito" type="radio" :value="opcion.valor" class="sr-only" />
                    <span class="block font-medium text-contenido">{{ opcion.titulo }}</span>
                    <span class="block text-xs text-suave">{{ opcion.detalle }}</span>
                </label>
            </div>
        </div>

        <!-- Congelada: se dice por qué y qué hacer, no sólo que no se puede. -->
        <p
            v-if="congelada"
            class="mt-4 rounded-lg px-3.5 py-2.5 text-sm"
            :style="{ backgroundColor: 'color-mix(in srgb, #d97706 12%, transparent)', color: '#b45309' }"
        >
            Esta rúbrica ya calificó a alguien, así que sus criterios están congelados: cambiarlos
            dejaría esas calificaciones sumando un total que ya no cuadra. Puedes renombrarla o
            apagarla; para hacerle una versión nueva, <strong>duplícala</strong>.
        </p>

        <div class="mt-5 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-contenido">
                Criterios
                <span class="ml-1 font-normal text-suave">— con qué se mira el trabajo</span>
            </h3>
            <span class="text-sm text-suave">
                Suma <strong class="text-contenido">{{ total }}</strong> puntos
            </span>
        </div>

        <p v-if="errorDe('criterios')" class="mt-1 text-xs text-red-600">{{ errorDe('criterios') }}</p>

        <div class="mt-2 space-y-2">
            <div
                v-for="(criterio, i) in form.criterios"
                :key="i"
                class="rounded-lg border"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <div class="flex items-start gap-2 px-3 py-2.5">
                    <button
                        type="button"
                        class="mt-1.5 shrink-0 text-suave transition"
                        :title="abierto === i ? 'Plegar' : 'Ver sus niveles'"
                        @click="alternar(i)"
                    >
                        {{ abierto === i ? '▾' : '▸' }}
                    </button>

                    <div class="min-w-0 flex-1">
                        <input
                            v-model="criterio.titulo"
                            type="text"
                            class="w-full rounded-lg border px-3 py-1.5 text-sm font-medium"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                            placeholder="Argumentación"
                            :disabled="congelada"
                        />
                        <p v-if="errorDe(`criterios.${i}.titulo`)" class="mt-1 text-xs text-red-600">
                            {{ errorDe(`criterios.${i}.titulo`) }}
                        </p>
                    </div>

                    <span class="mt-1.5 shrink-0 text-xs text-suave">
                        hasta <strong class="text-contenido">{{ maximoDe(criterio) }}</strong>
                    </span>

                    <div v-if="!congelada" class="mt-0.5 flex shrink-0 items-center">
                        <button type="button" class="px-1 text-suave" title="Subir" @click="moverCriterio(i, -1)">↑</button>
                        <button type="button" class="px-1 text-suave" title="Bajar" @click="moverCriterio(i, 1)">↓</button>
                        <button
                            v-if="form.criterios.length > 1"
                            type="button"
                            class="px-1 text-suave transition hover:text-red-600"
                            title="Quitar el criterio"
                            @click="quitarCriterio(i)"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <div v-if="abierto === i" class="border-t px-3 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <input
                        v-model="criterio.descripcion"
                        type="text"
                        class="mb-3 w-full rounded-lg border px-3 py-1.5 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                        placeholder="Qué se mira exactamente en este criterio (opcional)"
                        :disabled="congelada"
                    />

                    <p v-if="errorDe(`criterios.${i}.niveles`)" class="mb-2 text-xs text-red-600">
                        {{ errorDe(`criterios.${i}.niveles`) }}
                    </p>

                    <div
                        v-for="(nivel, j) in criterio.niveles"
                        :key="j"
                        class="mb-2 grid gap-2 sm:grid-cols-[9rem_5rem_1fr_1.5rem]"
                    >
                        <input
                            v-model="nivel.titulo"
                            type="text"
                            class="rounded-lg border px-2.5 py-1.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                            placeholder="Excelente"
                            :disabled="congelada"
                        />
                        <input
                            v-model="nivel.puntos"
                            type="number"
                            step="0.01"
                            min="0"
                            class="rounded-lg border px-2.5 py-1.5 text-sm tabular-nums"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                            :disabled="congelada"
                        />
                        <!-- El descriptor: es lo que el alumno lee ANTES de
                             entregar, y lo que hace que la nota se pueda
                             explicar sin el docente delante. -->
                        <input
                            v-model="nivel.descripcion"
                            type="text"
                            class="rounded-lg border px-2.5 py-1.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                            placeholder="Qué hay que haber hecho para merecerlo"
                            :disabled="congelada"
                        />
                        <button
                            v-if="!congelada && criterio.niveles.length > 2"
                            type="button"
                            class="text-suave transition hover:text-red-600"
                            title="Quitar el nivel"
                            @click="quitarNivel(criterio, j)"
                        >
                            ✕
                        </button>
                        <span v-else />
                    </div>

                    <button
                        v-if="!congelada"
                        type="button"
                        class="text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="agregarNivel(criterio)"
                    >
                        + Nivel
                    </button>
                </div>
            </div>
        </div>

        <button
            v-if="!congelada"
            type="button"
            class="mt-2 rounded-lg border px-3 py-1.5 text-sm"
            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
            @click="agregarCriterio"
        >
            + Criterio
        </button>

        <div class="mt-5 flex items-center gap-2">
            <BotonPrincipal :procesando="form.processing" texto="Guardar" icono="guardar" />
            <button
                type="button"
                class="rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @click="emit('cerrar')"
            >
                Cancelar
            </button>
        </div>
    </form>
</template>
