<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import SelectorDestinos from '@/Components/SelectorDestinos.vue';

/**
 * Las encuestas puestas en marcha.
 *
 * La aplicación es lo que cambia entre un semestre y otro: fechas,
 * destinatarios, docentes evaluados. El cuestionario se queda quieto, y por eso
 * dos ciclos se pueden comparar.
 */
interface Destino {
    tipo: string;
    destino_id: number | null;
}

interface Fila {
    id: number;
    titulo: string;
    cuestionario: string | null;
    tipo: string;
    estado: string;
    abierta: boolean;
    obligatoria: boolean;
    anonima: boolean;
    abre_en: string | null;
    cierra_en: string | null;
    sujetos: number;
    respuestas: number;
}

const props = defineProps<{
    aplicaciones: Fila[];
    cuestionarios: { id: number; titulo: string; es_plantilla: boolean }[];
    tiposDestino: { valor: string; etiqueta: string; necesita_id: boolean }[];
    opciones: Record<string, { id: number; nombre: string }[]>;
}>();

const editorAbierto = ref(false);

const form = useForm({
    encuesta_id: '' as number | string,
    titulo: '',
    instrucciones: '',
    tipo: 'general',
    abre_en: '',
    cierra_en: '',
    obligatoria: false,
    anonima: true,
    destinos: [] as Destino[],
});

function nuevo(): void {
    form.reset();
    form.clearErrors();
    form.defaults();
    editorAbierto.value = true;
}

function guardar(): void {
    form.post('/encuestas/aplicaciones', {
        preserveScroll: true,
        onSuccess: () => (editorAbierto.value = false),
    });
}

function eliminar(fila: Fila): void {
    if (!confirm(`¿Eliminar «${fila.titulo}»?`)) return;

    router.delete(`/encuestas/aplicaciones/${fila.id}`, { preserveScroll: true });
}

/** Qué le pasa a la encuesta hoy, dicho en una palabra. */
function situacion(a: Fila): { texto: string; clase: string } {
    if (a.estado === 'borrador') return { texto: 'Borrador', clase: 'bg-slate-100 text-slate-600' };
    if (a.estado === 'cerrada') return { texto: 'Cerrada', clase: 'bg-slate-100 text-slate-600' };
    if (a.abierta) return { texto: 'Abierta', clase: 'bg-emerald-50 text-emerald-700' };

    return { texto: 'Programada', clase: 'bg-amber-50 text-amber-800' };
}

const esDocente = computed(() => form.tipo === 'docente');
</script>

<template>
    <Head title="Encuestas de evaluación" />

    <AppLayout titulo="Encuestas de evaluación">
        <section class="tarjeta mb-4 flex flex-wrap items-center justify-between gap-4 p-6">
            <p class="max-w-2xl text-sm text-suave">
                Aplicar es poner un
                <Link href="/encuestas/cuestionarios" :style="{ color: 'var(--color-acento)' }">cuestionario</Link>
                en marcha con sus fechas y sus destinatarios. Al crearla se guarda una copia de las
                preguntas, así que editar la plantilla después no altera lo que ya se contestó.
            </p>
            <BotonAccion variante="nuevo" texto="Nueva aplicación" @click="nuevo" />
        </section>

        <section v-if="aplicaciones.length" class="space-y-3">
            <article v-for="a in aplicaciones" :key="a.id" class="tarjeta p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="rounded-full px-2.5 py-0.5 font-medium" :class="situacion(a).clase">
                                {{ situacion(a).texto }}
                            </span>
                            <span
                                class="rounded-full px-2.5 py-0.5"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                            >
                                {{ a.tipo === 'docente' ? 'Evaluación docente' : 'General' }}
                            </span>
                            <span v-if="a.obligatoria" class="rounded-full bg-red-50 px-2.5 py-0.5 text-red-700">
                                Obligatoria
                            </span>
                            <span v-if="a.anonima" class="text-suave">Anónima</span>
                        </div>

                        <Link :href="`/encuestas/aplicaciones/${a.id}`" class="mt-2 block font-semibold text-contenido hover:underline">
                            {{ a.titulo }}
                        </Link>

                        <p class="mt-1 text-xs text-suave">
                            <template v-if="a.tipo === 'docente'">
                                {{ a.sujetos }} {{ a.sujetos === 1 ? 'docente evaluado' : 'docentes evaluados' }} ·
                            </template>
                            {{ a.respuestas }} {{ a.respuestas === 1 ? 'respuesta' : 'respuestas' }}
                            <template v-if="a.abre_en"> · desde {{ a.abre_en.replace('T', ' ') }}</template>
                            <template v-if="a.cierra_en"> · hasta {{ a.cierra_en.replace('T', ' ') }}</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <Link
                            :href="`/encuestas/aplicaciones/${a.id}`"
                            class="rounded-lg border border-borde px-3 py-1.5 text-xs transition hover:bg-[color-mix(in_srgb,var(--color-acento)_8%,transparent)]"
                        >
                            Ver resultados
                        </Link>
                        <BotonAccion variante="eliminar" texto="Eliminar la aplicación" @click="eliminar(a)" />
                    </div>
                </div>
            </article>
        </section>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm text-suave">
            Ninguna encuesta aplicada todavía.
        </p>

        <Modal
            v-if="editorAbierto"
            etiqueta="Nueva aplicación"
            ancho="max-w-3xl"
            :formulario="form"
            @cerrar="editorAbierto = false"
        >
            <template #default="{ cerrar }">
                <form class="max-h-[75vh] space-y-4 overflow-y-auto p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold text-contenido">Nueva aplicación</h2>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Cuestionario *</label>
                        <select
                            v-model="form.encuesta_id"
                            required
                            class="w-full rounded-lg border border-borde bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="" disabled>Elige uno…</option>
                            <option v-for="c in cuestionarios" :key="c.id" :value="c.id">
                                {{ c.titulo }}{{ c.es_plantilla ? ' (plantilla)' : '' }}
                            </option>
                        </select>
                        <p v-if="form.errors.encuesta_id" class="mt-1 text-xs text-red-600">{{ form.errors.encuesta_id }}</p>
                    </div>

                    <CampoTexto v-model="form.titulo" etiqueta="Título de esta aplicación" requerido :error="form.errors.titulo" />
                    <CampoTextarea
                        v-model="form.instrucciones"
                        etiqueta="Instrucciones"
                        :filas="2"
                        ayuda="Lo que lee quien va a contestar, antes de empezar."
                        :error="form.errors.instrucciones"
                    />

                    <div>
                        <span class="mb-1 block text-sm font-medium">Tipo *</span>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                class="cursor-pointer rounded-xl border p-3 text-sm transition"
                                :style="{ borderColor: form.tipo === 'general' ? 'var(--color-acento)' : 'var(--color-borde)' }"
                            >
                                <span class="flex items-center gap-2 font-medium">
                                    <input v-model="form.tipo" type="radio" value="general"> General
                                </span>
                                <span class="mt-1 block text-xs text-suave">
                                    Se contesta una vez. Para preguntar por un tema: la plataforma,
                                    los servicios, una percepción.
                                </span>
                            </label>

                            <label
                                class="cursor-pointer rounded-xl border p-3 text-sm transition"
                                :style="{ borderColor: form.tipo === 'docente' ? 'var(--color-acento)' : 'var(--color-borde)' }"
                            >
                                <span class="flex items-center gap-2 font-medium">
                                    <input v-model="form.tipo" type="radio" value="docente"> Evaluación docente
                                </span>
                                <span class="mt-1 block text-xs text-suave">
                                    Se contesta una vez por cada docente. Después de crearla eliges a
                                    quiénes se evalúa, con filtros por ciclo, grupo o materia.
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="form.abre_en" etiqueta="Se abre" tipo="datetime-local" ayuda="En blanco: al publicarla." :error="form.errors.abre_en" />
                        <CampoTexto v-model="form.cierra_en" etiqueta="Se cierra" tipo="datetime-local" ayuda="En blanco: hasta que la cierres." :error="form.errors.cierra_en" />
                    </div>

                    <SelectorDestinos
                        v-model="form.destinos"
                        :tipos="tiposDestino"
                        :opciones="opciones"
                        url-alumnos="/api/buscar/alumnos"
                        :error="form.errors.destinos"
                    />

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.anonima" type="checkbox" class="mt-1">
                        <span>
                            Anónima
                            <span class="block text-xs text-suave">
                                No se guarda quién dijo qué. Para evaluar a un docente es casi
                                obligado: quien teme por su calificación no contesta lo que piensa.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.obligatoria" type="checkbox" class="mt-1">
                        <span>
                            Obligatoria
                            <span class="block text-xs text-suave">
                                Se interpone al entrar hasta que se conteste. Es la única forma de
                                conseguir una participación que sirva estadísticamente —la voluntaria
                                la contesta quien tiene algo que reclamar—, y por eso mismo conviene
                                usarla poco.
                            </span>
                        </span>
                    </label>

                    <p v-if="esDocente" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        Al guardar, la aplicación queda en borrador: falta elegir a qué docentes se
                        evalúa antes de poder publicarla.
                    </p>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Crear aplicación" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
