<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/**
 * Contestar una encuesta.
 *
 * ── Se envía completa, de una vez ──────────────────────────────────────────
 * No hay guardado parcial: la encuesta se contesta y se cierra. Por eso las
 * obligatorias se avisan antes de enviar y se vuelven a comprobar en el
 * servidor —dejar pasar un salto significa perder ese dato para siempre, sin
 * nadie a quien volver a preguntarle—.
 *
 * ── Y se dice si es anónima ────────────────────────────────────────────────
 * Arriba, donde se lee antes de empezar. Quien no sabe si lo que escriba lleva
 * su nombre contesta otra cosa distinta de la que piensa.
 */
interface PreguntaVista {
    id: number;
    texto: string;
    ayuda: string | null;
    tipo: string;
    requerida: boolean;
    config: Record<string, any>;
    opciones: { id: number; texto: string }[];
}

const props = defineProps<{
    aplicacion: {
        id: number;
        titulo: string;
        instrucciones: string | null;
        anonima: boolean;
        obligatoria: boolean;
        cierra_en: string | null;
    };
    sujeto: { id: number; docente: string | null; materia: string | null; grupo: string | null } | null;
    preguntas: PreguntaVista[];
}>();

const form = useForm<{ respuestas: Record<number, unknown> }>({
    respuestas: Object.fromEntries(
        props.preguntas.map((p) => [p.id, p.tipo === 'opcion_multiple' ? [] : null]),
    ),
});

/** Del 1 al máximo: los botones de la escala. */
function escalaDe(p: PreguntaVista): number[] {
    return Array.from({ length: Number(p.config.maximo ?? 5) }, (_, i) => i + 1);
}

function alternarOpcion(preguntaId: number, opcionId: number): void {
    const actuales = (form.respuestas[preguntaId] as number[]) ?? [];

    form.respuestas[preguntaId] = actuales.includes(opcionId)
        ? actuales.filter((x) => x !== opcionId)
        : [...actuales, opcionId];
}

function marcada(preguntaId: number, opcionId: number): boolean {
    return ((form.respuestas[preguntaId] as number[]) ?? []).includes(opcionId);
}

/** Lo que falta por contestar de lo obligatorio. */
const faltantes = computed(() =>
    props.preguntas.filter((p) => {
        if (! p.requerida) return false;

        const valor = form.respuestas[p.id];

        return valor === null || valor === '' || (Array.isArray(valor) && valor.length === 0);
    }),
);

function enviar(): void {
    const destino = props.sujeto === null
        ? `/mis-encuestas/${props.aplicacion.id}`
        : `/mis-encuestas/${props.aplicacion.id}/${props.sujeto.id}`;

    form.post(destino);
}
</script>

<template>
    <Head :title="aplicacion.titulo" />

    <AppLayout :titulo="aplicacion.titulo">
        <BotonVolver href="/mis-encuestas" texto="Mis encuestas" class="mb-4" />

        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span
                    v-if="aplicacion.anonima"
                    class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 font-medium text-emerald-700"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    Anónima
                </span>
                <span v-if="aplicacion.obligatoria" class="rounded-full bg-red-50 px-2.5 py-0.5 text-red-700">Obligatoria</span>
            </div>

            <p v-if="aplicacion.anonima" class="mt-2 text-sm text-suave">
                Queda constancia de que la contestaste, pero no de qué respondiste: tus respuestas no
                se guardan ligadas a tu nombre.
            </p>

            <p v-if="aplicacion.instrucciones" class="mt-2 whitespace-pre-line text-sm text-contenido">
                {{ aplicacion.instrucciones }}
            </p>

            <div v-if="sujeto" class="mt-3 rounded-xl border border-borde p-3">
                <p class="text-xs text-suave">Estás evaluando a</p>
                <p class="font-semibold text-contenido">{{ sujeto.docente }}</p>
                <p class="text-sm text-suave">
                    {{ sujeto.materia }}
                    <template v-if="sujeto.grupo"> · grupo {{ sujeto.grupo }}</template>
                </p>
            </div>
        </section>

        <form class="space-y-3" @submit.prevent="enviar">
            <article v-for="p in preguntas" :key="p.id" class="tarjeta p-5">
                <h3 class="font-medium text-contenido">
                    {{ p.texto }}
                    <span v-if="p.requerida" class="text-red-600">*</span>
                </h3>
                <p v-if="p.ayuda" class="mt-0.5 text-xs text-suave">{{ p.ayuda }}</p>

                <!-- Escala: botones grandes, con las etiquetas de los extremos. -->
                <div v-if="p.tipo === 'escala'" class="mt-3">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="n in escalaDe(p)"
                            :key="n"
                            type="button"
                            class="h-10 w-10 rounded-lg border text-sm font-medium transition"
                            :style="form.respuestas[p.id] === n
                                ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)', borderColor: 'var(--color-acento)' }
                                : { borderColor: 'var(--color-borde)' }"
                            @click="form.respuestas[p.id] = n"
                        >
                            {{ n }}
                        </button>
                    </div>
                    <div class="mt-1.5 flex justify-between text-xs text-suave">
                        <span>{{ p.config.etiqueta_min ?? '1' }}</span>
                        <span>{{ p.config.etiqueta_max ?? p.config.maximo ?? 5 }}</span>
                    </div>
                </div>

                <!-- Sí o no -->
                <div v-else-if="p.tipo === 'si_no'" class="mt-3 flex gap-2">
                    <button
                        v-for="opcion in [{ v: 1, t: 'Sí' }, { v: 0, t: 'No' }]"
                        :key="opcion.v"
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm transition"
                        :style="form.respuestas[p.id] === opcion.v
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)', borderColor: 'var(--color-acento)' }
                            : { borderColor: 'var(--color-borde)' }"
                        @click="form.respuestas[p.id] = opcion.v"
                    >
                        {{ opcion.t }}
                    </button>
                </div>

                <!-- Una opción -->
                <div v-else-if="p.tipo === 'opcion_unica'" class="mt-3 space-y-1.5">
                    <label
                        v-for="o in p.opciones"
                        :key="o.id"
                        class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition"
                        :style="{ borderColor: form.respuestas[p.id] === o.id ? 'var(--color-acento)' : 'var(--color-borde)' }"
                    >
                        <input v-model="form.respuestas[p.id]" type="radio" :value="o.id">
                        {{ o.texto }}
                    </label>
                </div>

                <!-- Varias opciones -->
                <div v-else-if="p.tipo === 'opcion_multiple'" class="mt-3 space-y-1.5">
                    <label
                        v-for="o in p.opciones"
                        :key="o.id"
                        class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition"
                        :style="{ borderColor: marcada(p.id, o.id) ? 'var(--color-acento)' : 'var(--color-borde)' }"
                    >
                        <input type="checkbox" :checked="marcada(p.id, o.id)" @change="alternarOpcion(p.id, o.id)">
                        {{ o.texto }}
                    </label>
                </div>

                <!-- Número -->
                <input
                    v-else-if="p.tipo === 'numerica'"
                    v-model.number="form.respuestas[p.id]"
                    type="number"
                    class="mt-3 w-40 rounded-lg border border-borde bg-transparent px-3 py-2 text-sm"
                >

                <!-- Abierta -->
                <textarea
                    v-else
                    v-model="form.respuestas[p.id]"
                    rows="3"
                    class="mt-3 w-full rounded-lg border border-borde bg-transparent px-3 py-2 text-sm"
                    placeholder="Tu respuesta"
                />
            </article>

            <div class="tarjeta flex flex-wrap items-center justify-between gap-3 p-4">
                <!-- Se avisa qué falta ANTES de enviar: la encuesta se contesta
                     una vez y no hay vuelta atrás. -->
                <p v-if="faltantes.length" class="text-xs text-amber-700">
                    Falta contestar {{ faltantes.length }}
                    {{ faltantes.length === 1 ? 'pregunta obligatoria' : 'preguntas obligatorias' }}.
                </p>
                <p v-else class="text-xs text-suave">Todo listo. Al enviar ya no se puede modificar.</p>

                <BotonPrincipal
                    :procesando="form.processing"
                    :deshabilitado="faltantes.length > 0"
                    texto="Enviar respuestas"
                />
            </div>
        </form>
    </AppLayout>
</template>
