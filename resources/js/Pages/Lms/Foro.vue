<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import { toast } from 'vue-sonner';

/*
 * El foro de una actividad.
 *
 * Lista de temas a la izquierda y el tema abierto a la derecha, como cualquier
 * foro: entrar a uno no debe hacer perder de vista los demás.
 *
 * Publicar cuenta como entregar —el servidor lo registra—, así que si el foro
 * pondera, el docente lo califica desde su matriz junto a las tareas.
 */
interface Respuesta {
    id: number;
    autor: string;
    persona_id: number;
    cuerpo: string;
    en: string | null;
    hijas?: Respuesta[];
}

const props = defineProps<{
    materia: { id: number; nombre: string };
    actividad: {
        id: number;
        titulo: string;
        instrucciones: string | null;
        cierra_en: string | null;
        abierta: boolean;
        pondera: boolean;
        puntos: number;
    };
    volver: { href: string; texto: string };
    yo: number;
    moderador: boolean;
    temas: { id: number; titulo: string; autor: string; fijado: boolean; cerrado: boolean; respuestas: number; en: string | null }[];
    abierto: {
        id: number;
        titulo: string;
        cuerpo: string;
        autor: string;
        persona_id: number;
        en: string | null;
        fijado: boolean;
        cerrado: boolean;
        respuestas: Respuesta[];
    } | null;
}>();

const base = `/materias/${props.materia.id}/foros/${props.actividad.id}`;

/* ── Abrir un tema ─────────────────────────────────────────────────────── */

const abriendo = ref(false);
const formTema = useForm({ titulo: '', cuerpo: '' });

function crearTema(): void {
    formTema.post(`${base}/temas`, {
        onSuccess: () => {
            abriendo.value = false;
            formTema.reset();
        },
        onError: (e) => toast.error(Object.values(e)[0] ?? 'Revisa el tema.'),
    });
}

/* ── Responder ─────────────────────────────────────────────────────────── */

/** A qué se responde: null es al tema, un id es a esa respuesta. */
const respondiendoA = ref<number | null>(null);
const formRespuesta = useForm<{ cuerpo: string; responde_a_id: number | null }>({
    cuerpo: '',
    responde_a_id: null,
});

function responder(): void {
    if (props.abierto === null) return;

    formRespuesta.responde_a_id = respondiendoA.value;

    formRespuesta.post(`${base}/temas/${props.abierto.id}/respuestas`, {
        preserveScroll: true,
        onSuccess: () => {
            formRespuesta.reset();
            respondiendoA.value = null;
        },
        onError: (e) => toast.error(Object.values(e)[0] ?? 'No se pudo publicar.'),
    });
}

/* ── Moderar ───────────────────────────────────────────────────────────── */

function moderar(campo: 'fijado' | 'cerrado'): void {
    if (props.abierto === null) return;

    router.put(
        `${base}/temas/${props.abierto.id}`,
        {
            fijado: campo === 'fijado' ? !props.abierto.fijado : props.abierto.fijado,
            cerrado: campo === 'cerrado' ? !props.abierto.cerrado : props.abierto.cerrado,
        },
        { preserveScroll: true },
    );
}

function eliminarTema(): void {
    if (props.abierto === null) return;
    if (!confirm(`¿Eliminar «${props.abierto.titulo}» y sus respuestas?`)) return;

    router.delete(`${base}/temas/${props.abierto.id}`);
}

function irA(temaId: number): void {
    router.get(base, { tema: temaId }, { preserveState: false });
}

const puedeBorrar = (personaId: number): boolean => props.moderador || personaId === props.yo;

const iniciales = (nombre: string): string =>
    nombre.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
</script>

<template>
    <Head :title="`Foro · ${actividad.titulo}`" />

    <AppLayout titulo="Foro">
        <section class="tarjeta p-6">
            <BotonVolver :href="volver.href" :texto="volver.texto" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-contenido">{{ actividad.titulo }}</h2>
                    <p class="mt-0.5 text-sm text-suave">{{ materia.nombre }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <PildoraEstado
                        :texto="actividad.abierta ? 'Abierto' : 'Cerrado'"
                        :color="actividad.abierta ? '#16a34a' : '#dc2626'"
                    />
                    <PildoraEstado
                        v-if="actividad.pondera"
                        :texto="`Vale ${actividad.puntos} puntos`"
                        color="var(--color-acento)"
                        sin-capitalizar
                    />
                </div>
            </div>

            <p v-if="actividad.instrucciones" class="mt-4 whitespace-pre-line text-sm">
                {{ actividad.instrucciones }}
            </p>

            <p v-if="actividad.cierra_en" class="mt-2 text-xs text-suave">
                Participa hasta el {{ actividad.cierra_en }}.
            </p>

            <div v-if="actividad.abierta" class="mt-5">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="abriendo = !abriendo"
                >
                    {{ abriendo ? 'Cancelar' : 'Abrir un tema' }}
                </button>
            </div>

            <form
                v-if="abriendo"
                class="mt-4 space-y-3 rounded-lg border p-4"
                :style="{ borderColor: 'var(--color-borde)', borderLeft: '3px solid var(--color-acento)' }"
                @submit.prevent="crearTema"
            >
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Título</span>
                    <input
                        v-model="formTema.titulo"
                        type="text"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">Tu planteamiento</span>
                    <textarea
                        v-model="formTema.cuerpo"
                        rows="4"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <button
                    type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    :disabled="formTema.processing"
                >
                    Publicar tema
                </button>
            </form>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="grid gap-0 md:grid-cols-[260px_1fr]">
                <!-- Temas -->
                <aside class="border-borde md:border-r">
                    <header class="border-b border-borde px-4 py-3">
                        <h3 class="text-sm font-semibold text-contenido">Temas ({{ temas.length }})</h3>
                    </header>

                    <ul v-if="temas.length" class="divide-y divide-borde">
                        <li v-for="t in temas" :key="t.id">
                            <button
                                type="button"
                                class="w-full px-4 py-3 text-left transition"
                                :style="{
                                    backgroundColor: t.id === abierto?.id
                                        ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)'
                                        : 'transparent',
                                    borderLeft: t.id === abierto?.id ? '3px solid var(--color-acento)' : '3px solid transparent',
                                }"
                                @click="irA(t.id)"
                            >
                                <span class="flex items-center gap-1.5">
                                    <span v-if="t.fijado" title="Fijado">📌</span>
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-contenido">
                                        {{ t.titulo }}
                                    </span>
                                </span>
                                <span class="mt-0.5 block text-[11px] text-suave">
                                    {{ t.autor }} · {{ t.respuestas }} respuesta(s)
                                    <span v-if="t.cerrado"> · cerrado</span>
                                </span>
                            </button>
                        </li>
                    </ul>

                    <p v-else class="px-4 py-8 text-center text-sm text-suave">
                        Todavía no hay temas.
                    </p>
                </aside>

                <!-- Tema abierto -->
                <div class="min-h-[24rem] p-5">
                    <p v-if="abierto === null" class="py-16 text-center text-sm text-suave">
                        Elige un tema de la izquierda para leerlo.
                    </p>

                    <div v-else>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-contenido">{{ abierto.titulo }}</h3>
                                <p class="mt-0.5 text-xs text-suave">{{ abierto.autor }} · {{ abierto.en }}</p>
                            </div>

                            <div class="flex shrink-0 flex-wrap gap-1.5">
                                <button
                                    v-if="moderador"
                                    type="button"
                                    class="rounded-lg border px-2 py-1 text-xs"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    @click="moderar('fijado')"
                                >
                                    {{ abierto.fijado ? 'Quitar fijado' : 'Fijar' }}
                                </button>
                                <button
                                    v-if="moderador"
                                    type="button"
                                    class="rounded-lg border px-2 py-1 text-xs"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    @click="moderar('cerrado')"
                                >
                                    {{ abierto.cerrado ? 'Reabrir' : 'Cerrar' }}
                                </button>
                                <button
                                    v-if="puedeBorrar(abierto.persona_id)"
                                    type="button"
                                    class="rounded-lg border px-2 py-1 text-xs"
                                    :style="{ borderColor: '#dc2626', color: '#dc2626' }"
                                    @click="eliminarTema"
                                >
                                    Eliminar
                                </button>
                            </div>
                        </div>

                        <p class="mt-3 whitespace-pre-line text-sm">{{ abierto.cuerpo }}</p>

                        <!-- Respuestas -->
                        <ul class="mt-6 space-y-4 border-t border-borde pt-4">
                            <li v-for="r in abierto.respuestas" :key="r.id">
                                <div class="flex gap-3">
                                    <span
                                        class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 14%, transparent)', color: 'var(--color-suave)' }"
                                    >
                                        {{ iniciales(r.autor) }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs text-suave">{{ r.autor }} · {{ r.en }}</p>
                                        <p class="mt-0.5 whitespace-pre-line text-sm">{{ r.cuerpo }}</p>
                                        <button
                                            v-if="!abierto.cerrado && actividad.abierta"
                                            type="button"
                                            class="mt-1 text-xs font-medium"
                                            :style="{ color: 'var(--color-acento)' }"
                                            @click="respondiendoA = respondiendoA === r.id ? null : r.id"
                                        >
                                            {{ respondiendoA === r.id ? 'Cancelar' : 'Responder' }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Un solo nivel de anidamiento -->
                                <ul
                                    v-if="r.hijas?.length"
                                    class="ml-11 mt-3 space-y-3 border-l pl-4"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                >
                                    <li v-for="h in r.hijas" :key="h.id">
                                        <p class="text-xs text-suave">{{ h.autor }} · {{ h.en }}</p>
                                        <p class="mt-0.5 whitespace-pre-line text-sm">{{ h.cuerpo }}</p>
                                    </li>
                                </ul>
                            </li>
                        </ul>

                        <p
                            v-if="abierto.cerrado"
                            class="mt-5 rounded-lg px-3 py-2 text-sm"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)' }"
                        >
                            Este tema está cerrado: ya no admite respuestas.
                        </p>

                        <form
                            v-else-if="actividad.abierta"
                            class="mt-5 space-y-2 border-t border-borde pt-4"
                            @submit.prevent="responder"
                        >
                            <p v-if="respondiendoA !== null" class="text-xs" :style="{ color: 'var(--color-acento)' }">
                                Respondiendo a una respuesta.
                                <button type="button" class="underline" @click="respondiendoA = null">
                                    Responder al tema en su lugar
                                </button>
                            </p>
                            <textarea
                                v-model="formRespuesta.cuerpo"
                                rows="3"
                                class="w-full rounded-lg border px-3 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                placeholder="Escribe tu respuesta."
                            />
                            <button
                                type="submit"
                                class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                :style="{ backgroundColor: 'var(--color-acento)' }"
                                :disabled="formRespuesta.processing || formRespuesta.cuerpo.trim() === ''"
                            >
                                Publicar respuesta
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
