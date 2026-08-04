<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';

/**
 * El editor de preguntas.
 *
 * ── Por qué se ve el tipo explicado y no sólo su nombre ────────────────────
 * De la elección del tipo depende qué se podrá hacer con la respuesta al
 * cerrar: una escala se promedia y ordena, unas opciones se cuentan, una
 * abierta hay que leerla. Descubrirlo cuando ya contestaron trescientas
 * personas es descubrir que los datos no responden la pregunta que la escuela
 * quería hacerse, y ya no hay a quién volver a preguntarle.
 *
 * ── Lo que hace rápida la captura ──────────────────────────────────────────
 * Un cuestionario de evaluación docente son ocho o diez preguntas casi
 * idénticas —la misma escala, las mismas etiquetas— y una o dos abiertas.
 * Escribirlas de una en una, cambiando el tipo a mano cada vez, es lo que hace
 * que nadie quiera volver a tocar el instrumento. De ahí las tres cosas que
 * más ahorran: elegir el tipo AL agregar, duplicar una pregunta ya armada, y
 * pegar todas las opciones de golpe.
 */
interface OpcionForm {
    texto: string;
    valor: number | null;
}

interface PreguntaForm {
    texto: string;
    ayuda: string;
    tipo: string;
    requerida: boolean;
    config: Record<string, unknown>;
    opciones: OpcionForm[];
}

interface TipoPregunta {
    valor: string;
    texto: string;
    descripcion: string;
    requiere_opciones: boolean;
}

const props = defineProps<{
    encuesta: {
        id: number;
        titulo: string;
        descripcion: string | null;
        es_plantilla: boolean;
        activa: boolean;
        aplicada: boolean;
        /** Su aplicación, cuando el cuestionario es de un solo uso. */
        aplicacion: { id: number; titulo: string } | null;
    };
    preguntas: Array<PreguntaForm & { id: number }>;
    tiposPregunta: TipoPregunta[];
}>();

const form = useForm({
    preguntas: props.preguntas.map((p) => ({
        texto: p.texto,
        ayuda: p.ayuda ?? '',
        tipo: p.tipo,
        requerida: p.requerida,
        config: { ...p.config },
        opciones: p.opciones.map((o) => ({ texto: o.texto, valor: o.valor })),
    })) as PreguntaForm[],
});

/** Cuál está abierta para editar. El resto se ve plegado. */
const abierta = ref<number | null>(props.preguntas.length === 0 ? null : 0);

function tipoDe(clave: string): TipoPregunta | undefined {
    return props.tiposPregunta.find((t) => t.valor === clave);
}

/** Lo que cada tipo necesita para funcionar desde el primer momento. */
function preguntaNueva(tipo: string): PreguntaForm {
    const base: PreguntaForm = { texto: '', ayuda: '', tipo, requerida: true, config: {}, opciones: [] };

    if (tipo === 'escala') {
        base.config = { maximo: 5, etiqueta_min: 'Nunca', etiqueta_max: 'Siempre' };
    }

    if (tipoDe(tipo)?.requiere_opciones) {
        base.opciones = [{ texto: '', valor: null }, { texto: '', valor: null }];
    }

    return base;
}

/**
 * Agregar eligiendo el tipo, en vez de agregar y luego cambiarlo.
 *
 * Con un solo botón genérico toda pregunta nacía como escala y había que
 * corregirla: dos gestos por cada pregunta que no lo fuera.
 */
async function agregar(tipo: string): Promise<void> {
    form.preguntas.push(preguntaNueva(tipo));
    abierta.value = form.preguntas.length - 1;

    await nextTick();
    enfocarUltima();
}

/** Duplicar: lo que salva capturar ocho escalas con las mismas etiquetas. */
async function duplicar(i: number): Promise<void> {
    const original = form.preguntas[i];

    form.preguntas.splice(i + 1, 0, {
        ...original,
        config: { ...original.config },
        opciones: original.opciones.map((o) => ({ ...o })),
    });

    abierta.value = i + 1;

    await nextTick();
    enfocarUltima();
}

function enfocarUltima(): void {
    const campos = document.querySelectorAll<HTMLInputElement>('[data-texto-pregunta]');
    campos[abierta.value ?? campos.length - 1]?.focus();
}

function cambiarTipo(pregunta: PreguntaForm): void {
    if (tipoDe(pregunta.tipo)?.requiere_opciones && pregunta.opciones.length === 0) {
        pregunta.opciones = [{ texto: '', valor: null }, { texto: '', valor: null }];
    }

    if (pregunta.tipo === 'escala' && pregunta.config.maximo === undefined) {
        pregunta.config = { maximo: 5, etiqueta_min: 'Nunca', etiqueta_max: 'Siempre' };
    }
}

function mover(i: number, direccion: -1 | 1): void {
    const destino = i + direccion;

    if (destino < 0 || destino >= form.preguntas.length) return;

    const copia = [...form.preguntas];
    [copia[i], copia[destino]] = [copia[destino], copia[i]];
    form.preguntas = copia;

    if (abierta.value === i) abierta.value = destino;
}

function quitar(i: number): void {
    form.preguntas.splice(i, 1);

    if (abierta.value === i) abierta.value = null;
}

/**
 * Pegar las opciones de golpe, una por renglón.
 *
 * «Siempre / Casi siempre / A veces / Nunca» son cuatro clics y cuatro campos
 * capturándolas de una en una; pegadas es un gesto. Es la forma en que la
 * gente ya las tiene escritas: en una lista.
 */
const pegando = ref<number | null>(null);
const textoPegado = ref('');

function abrirPegado(i: number): void {
    pegando.value = i;
    textoPegado.value = '';
}

function pegarOpciones(pregunta: PreguntaForm): void {
    const nuevas = textoPegado.value
        .split('\n')
        .map((t) => t.trim())
        .filter((t) => t !== '')
        .map((t) => ({ texto: t, valor: null }));

    if (nuevas.length === 0) {
        pegando.value = null;

        return;
    }

    // Se reemplazan las vacías que estorban y se conservan las ya escritas.
    pregunta.opciones = [...pregunta.opciones.filter((o) => o.texto.trim() !== ''), ...nuevas];
    pegando.value = null;
}

/**
 * Numerar las opciones de mayor a menor.
 *
 * Es lo que convierte «siempre / a veces / nunca» en algo promediable, y a mano
 * significa teclear un peso por opción acordándose del orden.
 */
function numerarOpciones(pregunta: PreguntaForm): void {
    const total = pregunta.opciones.length;

    pregunta.opciones = pregunta.opciones.map((o, i) => ({ ...o, valor: total - i }));
}

function guardar(): void {
    form.put(`/encuestas/cuestionarios/${props.encuesta.id}/preguntas`, { preserveScroll: true });
}

/** Cuántas se van a poder promediar: son las que sirven para comparar. */
const promediables = computed(
    () => form.preguntas.filter((p) => ['escala', 'numerica'].includes(p.tipo)).length,
);

/** Sin texto no es una pregunta: el servidor la rechaza y conviene avisar antes. */
const sinTexto = computed(() => form.preguntas.filter((p) => p.texto.trim() === '').length);

/** Un resumen legible de cómo quedará, para revisar sin abrir cada una. */
function resumen(p: PreguntaForm): string {
    if (p.tipo === 'escala') {
        return `Del 1 al ${p.config.maximo ?? 5} · ${p.config.etiqueta_min ?? ''} → ${p.config.etiqueta_max ?? ''}`;
    }

    if (tipoDe(p.tipo)?.requiere_opciones) {
        const textos = p.opciones.map((o) => o.texto).filter(Boolean);

        return textos.length ? textos.join(' · ') : 'Sin opciones capturadas';
    }

    return tipoDe(p.tipo)?.texto ?? p.tipo;
}
</script>

<template>
    <Head :title="encuesta.titulo" />

    <AppLayout :titulo="encuesta.titulo">
        <!-- La vuelta lleva a donde se venía: a la encuesta si es de un solo
             uso —ahí falta publicarla— y al catálogo si es una plantilla. -->
        <BotonVolver
            v-if="encuesta.aplicacion"
            :href="`/encuestas/aplicaciones/${encuesta.aplicacion.id}`"
            :texto="encuesta.aplicacion.titulo"
            class="mb-4"
        />
        <BotonVolver v-else href="/encuestas/cuestionarios" texto="Cuestionarios" class="mb-4" />

        <!--
            Con respuestas detrás, cambiar las preguntas dejaría los resultados
            atribuidos a preguntas que nadie vio. El servidor lo impide; aquí se
            avisa antes de que alguien escriba media hora en balde.
        -->
        <div v-if="encuesta.aplicada" class="tarjeta mb-4 border-l-4 border-l-amber-500 p-4 text-sm">
            Este cuestionario ya se aplicó. Si necesitas cambiar las preguntas,
            <strong>duplícalo</strong> y edita la copia: lo contestado quedaría atribuido a
            preguntas distintas de las que la gente vio.
        </div>

        <!-- Vacío: en vez de un botón mudo, los tipos con lo que da cada uno. -->
        <section v-if="! form.preguntas.length" class="tarjeta p-6">
            <h2 class="font-semibold text-contenido">Empieza por una pregunta</h2>
            <p class="mt-1 max-w-2xl text-sm text-suave">
                Elige el tipo según lo que quieras poder hacer con las respuestas al cerrar: las
                escalas se promedian y comparan entre ciclos, las opciones se reparten en
                porcentajes y las abiertas hay que leerlas una por una.
            </p>

            <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="t in tiposPregunta"
                    :key="t.valor"
                    type="button"
                    class="rounded-xl border border-borde p-3 text-left transition hover:bg-[color-mix(in_srgb,var(--color-acento)_6%,transparent)]"
                    @click="agregar(t.valor)"
                >
                    <span class="block text-sm font-medium text-contenido">{{ t.texto }}</span>
                    <span class="mt-0.5 block text-xs text-suave">{{ t.descripcion }}</span>
                </button>
            </div>
        </section>

        <form v-else class="space-y-2 pb-24" @submit.prevent="guardar">
            <article
                v-for="(p, i) in form.preguntas"
                :key="i"
                class="tarjeta overflow-hidden"
                :style="abierta === i ? { borderColor: 'var(--color-acento)' } : {}"
            >
                <!-- Cabecera: siempre visible, para leer el cuestionario de
                     corrido sin abrir doce tarjetas. -->
                <div class="flex items-start gap-3 p-4">
                    <span class="mt-0.5 w-5 shrink-0 text-right text-xs font-semibold text-suave">{{ i + 1 }}</span>

                    <button
                        type="button"
                        class="min-w-0 flex-1 text-left"
                        @click="abierta = abierta === i ? null : i"
                    >
                        <span
                            class="block text-sm font-medium"
                            :class="p.texto.trim() === '' ? 'text-red-600' : 'text-contenido'"
                        >
                            {{ p.texto.trim() === '' ? 'Sin escribir' : p.texto }}
                        </span>
                        <span class="mt-0.5 block truncate text-xs text-suave">
                            {{ tipoDe(p.tipo)?.texto }} · {{ resumen(p) }}
                            <template v-if="! p.requerida"> · opcional</template>
                        </span>
                    </button>

                    <div class="flex shrink-0 items-center gap-0.5">
                        <button type="button" class="rounded p-1.5 text-suave transition hover:bg-black/5" title="Subir" @click="mover(i, -1)">↑</button>
                        <button type="button" class="rounded p-1.5 text-suave transition hover:bg-black/5" title="Bajar" @click="mover(i, 1)">↓</button>
                        <button type="button" class="rounded p-1.5 text-suave transition hover:bg-black/5" title="Duplicar esta pregunta" @click="duplicar(i)">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                            </svg>
                        </button>
                        <button type="button" class="rounded p-1.5 text-red-600 transition hover:bg-black/5" title="Quitar la pregunta" @click="quitar(i)">×</button>
                    </div>
                </div>

                <!-- Cuerpo: sólo el de la pregunta abierta. -->
                <div v-if="abierta === i" class="space-y-3 border-t border-borde p-4">
                    <input
                        v-model="p.texto"
                        data-texto-pregunta
                        type="text"
                        required
                        placeholder="Escribe la pregunta"
                        class="w-full rounded-lg border border-borde bg-transparent px-3 py-2 text-sm font-medium"
                    >

                    <input
                        v-model="p.ayuda"
                        type="text"
                        placeholder="Aclaración (opcional): evita que cada quien entienda otra cosa"
                        class="w-full rounded-lg border border-borde bg-transparent px-3 py-1.5 text-xs"
                    >

                    <div class="flex flex-wrap items-center gap-3">
                        <select
                            v-model="p.tipo"
                            class="rounded-lg border border-borde bg-transparent px-3 py-1.5 text-sm"
                            @change="cambiarTipo(p)"
                        >
                            <option v-for="t in tiposPregunta" :key="t.valor" :value="t.valor">{{ t.texto }}</option>
                        </select>

                        <label class="flex items-center gap-1.5 text-xs">
                            <input v-model="p.requerida" type="checkbox">
                            Obligatoria
                        </label>

                        <span class="text-xs text-suave">{{ tipoDe(p.tipo)?.descripcion }}</span>
                    </div>

                    <!-- Escala -->
                    <div v-if="p.tipo === 'escala'" class="rounded-lg border border-borde p-3">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span>De 1 a</span>
                            <input
                                v-model.number="p.config.maximo"
                                type="number"
                                min="2"
                                max="10"
                                class="w-16 rounded-lg border border-borde bg-transparent px-2 py-1"
                            >
                            <input
                                v-model="p.config.etiqueta_min"
                                type="text"
                                placeholder="1 significa…"
                                class="w-32 rounded-lg border border-borde bg-transparent px-2 py-1"
                            >
                            <input
                                v-model="p.config.etiqueta_max"
                                type="text"
                                placeholder="el máximo significa…"
                                class="w-36 rounded-lg border border-borde bg-transparent px-2 py-1"
                            >
                        </div>

                        <!-- Cómo lo verá quien conteste: revisar la escala en la
                             cabeza es donde se cuelan los máximos absurdos. -->
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <span
                                v-for="n in Math.min(10, Math.max(2, Number(p.config.maximo) || 5))"
                                :key="n"
                                class="grid h-7 w-7 place-items-center rounded border border-borde text-xs text-suave"
                            >{{ n }}</span>
                        </div>
                    </div>

                    <!-- Opciones -->
                    <div v-if="tipoDe(p.tipo)?.requiere_opciones" class="rounded-lg border border-borde p-3">
                        <div class="space-y-1.5">
                            <div v-for="(o, j) in p.opciones" :key="j" class="flex items-center gap-2">
                                <span class="w-4 shrink-0 text-right text-xs text-suave">{{ j + 1 }}</span>
                                <input
                                    v-model="o.texto"
                                    type="text"
                                    required
                                    placeholder="Texto de la opción"
                                    class="min-w-0 flex-1 rounded-lg border border-borde bg-transparent px-3 py-1.5 text-sm"
                                >
                                <!-- El peso convierte unas opciones en algo
                                     promediable: «siempre» vale más que «nunca». -->
                                <input
                                    v-model.number="o.valor"
                                    type="number"
                                    step="0.5"
                                    placeholder="peso"
                                    title="Opcional. Si las opciones ordenan algo, su peso permite promediarlas."
                                    class="w-20 rounded-lg border border-borde bg-transparent px-2 py-1.5 text-xs"
                                >
                                <button type="button" class="shrink-0 text-xs text-red-600" @click="p.opciones.splice(j, 1)">
                                    Quitar
                                </button>
                            </div>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1 text-xs"
                                @click="p.opciones.push({ texto: '', valor: null })"
                            >
                                Agregar opción
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1 text-xs"
                                title="Una por renglón; es como ya las tienes escritas"
                                @click="abrirPegado(i)"
                            >
                                Pegar varias
                            </button>
                            <button
                                v-if="p.opciones.length > 1"
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1 text-xs"
                                title="Da peso de mayor a menor, para poder promediarlas"
                                @click="numerarOpciones(p)"
                            >
                                Numerar de mayor a menor
                            </button>
                        </div>

                        <div v-if="pegando === i" class="mt-2">
                            <textarea
                                v-model="textoPegado"
                                rows="4"
                                autofocus
                                placeholder="Una opción por renglón:&#10;Siempre&#10;Casi siempre&#10;A veces&#10;Nunca"
                                class="w-full rounded-lg border border-borde bg-transparent px-3 py-2 text-sm"
                            ></textarea>
                            <div class="mt-1.5 flex gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1 text-xs font-medium"
                                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                    @click="pegarOpciones(p)"
                                >
                                    Agregar estas opciones
                                </button>
                                <button type="button" class="rounded-lg border border-borde px-3 py-1 text-xs" @click="pegando = null">
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <p v-if="form.errors.preguntas" class="text-sm text-red-600">{{ form.errors.preguntas }}</p>

            <!-- Agregar otra, con el tipo elegido de una vez. -->
            <div class="tarjeta flex flex-wrap items-center gap-2 p-3">
                <span class="text-xs text-suave">Agregar:</span>
                <button
                    v-for="t in tiposPregunta"
                    :key="t.valor"
                    type="button"
                    class="rounded-lg border border-borde px-3 py-1.5 text-xs transition hover:bg-[color-mix(in_srgb,var(--color-acento)_8%,transparent)]"
                    :title="t.descripcion"
                    @click="agregar(t.valor)"
                >
                    {{ t.texto }}
                </button>
            </div>
        </form>

        <!--
            La barra de guardar va fija abajo.
            Con quince preguntas, tenerla al final del formulario obliga a bajar
            hasta el fondo para guardar un cambio hecho en la tercera.
        -->
        <div
            v-if="form.preguntas.length"
            class="sticky bottom-0 z-10 -mx-4 mt-3 border-t border-borde px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-superficie) 92%, transparent)' }"
        >
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-suave">
                    {{ form.preguntas.length }}
                    {{ form.preguntas.length === 1 ? 'pregunta' : 'preguntas' }} ·
                    <!-- Es el dato que decide si la encuesta va a servir para
                         comparar o sólo para leerse una por una. -->
                    {{ promediables }} {{ promediables === 1 ? 'promediable' : 'promediables' }}
                    <span v-if="sinTexto" class="ml-1 text-red-600">
                        · {{ sinTexto }} sin escribir
                    </span>
                </p>

                <BotonPrincipal
                    tipo="button"
                    :procesando="form.processing"
                    :deshabilitado="sinTexto > 0"
                    texto="Guardar preguntas"
                    @click="guardar"
                />
            </div>
        </div>
    </AppLayout>
</template>
