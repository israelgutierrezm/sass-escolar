<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
    encuesta: { id: number; titulo: string; descripcion: string | null; es_plantilla: boolean; activa: boolean; aplicada: boolean };
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

function tipoDe(clave: string): TipoPregunta | undefined {
    return props.tiposPregunta.find((t) => t.valor === clave);
}

function agregar(): void {
    form.preguntas.push({
        texto: '',
        ayuda: '',
        tipo: 'escala',
        requerida: true,
        config: { maximo: 5, etiqueta_min: 'Nunca', etiqueta_max: 'Siempre' },
        opciones: [],
    });
}

/**
 * Al cambiar de tipo se ajusta lo que ese tipo necesita.
 *
 * Sin esto, pasar de escala a opciones dejaba una pregunta sin alternativas
 * que al guardarse no preguntaba nada.
 */
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
}

function guardar(): void {
    form.put(`/encuestas/cuestionarios/${props.encuesta.id}/preguntas`, { preserveScroll: true });
}

/** Cuántas se van a poder promediar: son las que sirven para comparar. */
const promediables = computed(
    () => form.preguntas.filter((p) => ['escala', 'numerica'].includes(p.tipo)).length,
);
</script>

<template>
    <Head :title="encuesta.titulo" />

    <AppLayout :titulo="encuesta.titulo">
        <BotonVolver href="/encuestas/cuestionarios" texto="Cuestionarios" class="mb-4" />

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

        <form class="space-y-3" @submit.prevent="guardar">
            <article
                v-for="(p, i) in form.preguntas"
                :key="i"
                class="tarjeta p-5"
            >
                <div class="flex items-start gap-3">
                    <span class="mt-1 text-xs font-semibold text-suave">{{ i + 1 }}</span>

                    <div class="min-w-0 flex-1 space-y-3">
                        <input
                            v-model="p.texto"
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
                        </div>

                        <!-- Qué se obtiene con este tipo, dicho antes de elegirlo. -->
                        <p class="text-xs text-suave">{{ tipoDe(p.tipo)?.descripcion }}</p>

                        <!-- Escala -->
                        <div v-if="p.tipo === 'escala'" class="flex flex-wrap items-center gap-2 text-xs">
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

                        <!-- Opciones -->
                        <div v-if="tipoDe(p.tipo)?.requiere_opciones" class="space-y-1.5">
                            <div v-for="(o, j) in p.opciones" :key="j" class="flex items-center gap-2">
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
                                <button type="button" class="text-xs text-red-600" @click="p.opciones.splice(j, 1)">
                                    Quitar
                                </button>
                            </div>

                            <button
                                type="button"
                                class="rounded-lg border border-borde px-3 py-1 text-xs"
                                @click="p.opciones.push({ texto: '', valor: null })"
                            >
                                Agregar opción
                            </button>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col gap-1">
                        <button type="button" class="rounded p-1 text-suave hover:bg-black/5" title="Subir" @click="mover(i, -1)">↑</button>
                        <button type="button" class="rounded p-1 text-suave hover:bg-black/5" title="Bajar" @click="mover(i, 1)">↓</button>
                        <button type="button" class="rounded p-1 text-red-600 hover:bg-black/5" title="Quitar la pregunta" @click="form.preguntas.splice(i, 1)">×</button>
                    </div>
                </div>
            </article>

            <p v-if="form.errors.preguntas" class="text-sm text-red-600">{{ form.errors.preguntas }}</p>

            <div class="tarjeta flex flex-wrap items-center justify-between gap-3 p-4">
                <div class="text-xs text-suave">
                    {{ form.preguntas.length }}
                    {{ form.preguntas.length === 1 ? 'pregunta' : 'preguntas' }} ·
                    <!-- Es el dato que decide si la encuesta va a servir para
                         comparar o sólo para leerse una por una. -->
                    {{ promediables }} {{ promediables === 1 ? 'promediable' : 'promediables' }}
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="agregar">
                        Agregar pregunta
                    </button>
                    <BotonPrincipal :procesando="form.processing" texto="Guardar preguntas" />
                </div>
            </div>
        </form>
    </AppLayout>
</template>
