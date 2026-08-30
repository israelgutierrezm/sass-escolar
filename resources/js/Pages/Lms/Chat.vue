<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import { toast } from 'vue-sonner';

/*
 * El chat de una materia: el canal del grupo y las conversaciones directas.
 *
 * La misma pantalla para el docente y el alumno. Lo único que cambia es con
 * quién puede uno abrir una directa, y eso lo decide el servidor: aquí solo se
 * pinta la lista que mandó.
 */
interface MensajeChat {
    id: number;
    persona_id: number;
    autor: string;
    cuerpo: string;
    en: string | null;
}

const props = defineProps<{
    materia: { id: number; nombre: string; abierta: boolean };
    yo: number;
    soy_docente: boolean;
    volver: { href: string; texto: string };
    conversaciones: { id: number; tipo: string; titulo: string; ultimo_mensaje_en: string | null; sin_leer: number }[];
    abierta: { id: number; tipo: string; titulo: string };
    mensajes: MensajeChat[];
    contactos: { id: number; nombre: string }[];
}>();

/*
 * Los mensajes viven aquí y no en los props: llegan por dos caminos —la carga
 * de la página y el sondeo— y mezclarlos en el prop obligaría a recargar toda
 * la pantalla cada vez que alguien escribe.
 */
const mensajes = ref<MensajeChat[]>([...props.mensajes]);
const hilo = ref<HTMLElement | null>(null);

watch(
    () => props.abierta.id,
    () => {
        mensajes.value = [...props.mensajes];
        alFinal();
    },
);

function alFinal(): void {
    nextTick(() => {
        if (hilo.value) hilo.value.scrollTop = hilo.value.scrollHeight;
    });
}

onMounted(alFinal);

/* ── Escribir ──────────────────────────────────────────────────────────── */

const form = useForm({ cuerpo: '' });

function enviar(): void {
    if (form.cuerpo.trim() === '') return;

    form.post(`/materias/${props.materia.id}/chat/${props.abierta.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.reset();
            sondear();
        },
        onError: (e) => toast.error(Object.values(e)[0] ?? 'No se pudo enviar.'),
    });
}

/*
 * Enter manda, Shift+Enter hace salto de línea. Es lo que la gente ya tiene en
 * los dedos de cualquier otro chat.
 */
function alTeclear(evento: KeyboardEvent): void {
    if (evento.key === 'Enter' && !evento.shiftKey) {
        evento.preventDefault();
        enviar();
    }
}

/* ── Sondeo ────────────────────────────────────────────────────────────── */

/*
 * Se pregunta por lo nuevo cada pocos segundos. No es tiempo real —eso pediría
 * websockets y un servidor que hoy no existe—, pero para el ida y vuelta entre
 * un alumno y su docente unos segundos de retraso no cambian nada.
 *
 * Va por `fetch` y no por Inertia: una visita recargaría la pantalla entera y
 * borraría lo que el usuario está escribiendo.
 */
let reloj: number | undefined;

/*
 * Un sondeo a la vez.
 *
 * Al mandar un mensaje se sondea de inmediato para verlo aparecer, y además el
 * reloj sondea cada cinco segundos. Cuando las dos llamadas se cruzaban —cosa
 * que pasa sola al escribir seguido— ambas leían el MISMO «último id» antes de
 * que ninguna hubiera insertado nada, así que las dos traían el mensaje recién
 * enviado y las dos lo metían en la lista: el mensaje salía duplicado en
 * pantalla, aunque en la base hubiera uno solo.
 */
let sondeando = false;

async function sondear(): Promise<void> {
    if (sondeando) return;

    sondeando = true;

    const ultimo = mensajes.value.length ? mensajes.value[mensajes.value.length - 1].id : 0;

    try {
        const r = await fetch(
            `/materias/${props.materia.id}/chat/${props.abierta.id}/nuevos?desde=${ultimo}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!r.ok) return;

        const datos = await r.json();

        /*
         * Y aun con el cerrojo, se filtra por id antes de insertar: el mensaje
         * puede llegar por el sondeo y por una recarga de props a la vez, y una
         * lista de chat no puede permitirse mostrar dos veces lo mismo. El
         * cerrojo evita el programa académico; esto evita cualquier otra.
         */
        const conocidos = new Set(mensajes.value.map((m) => m.id));
        const nuevos = (datos.mensajes ?? []).filter((m: MensajeChat) => !conocidos.has(m.id));

        if (nuevos.length) {
            mensajes.value.push(...nuevos);
            alFinal();
        }
    } catch {
        // Un sondeo fallido no merece aviso: el siguiente lo resuelve. Molestar
        // con un error cada vez que parpadea la red haría inusable la pantalla.
    } finally {
        sondeando = false;
    }
}

onMounted(() => {
    reloj = window.setInterval(sondear, 5000);
});

onBeforeUnmount(() => window.clearInterval(reloj));

/* ── Abrir una directa ─────────────────────────────────────────────────── */

const eligiendo = ref(false);

function abrirDirecta(personaId: number): void {
    eligiendo.value = false;
    router.post(`/materias/${props.materia.id}/chat/directa`, { persona_id: personaId });
}

function irA(conversacionId: number): void {
    router.get(`/materias/${props.materia.id}/chat`, { conversacion: conversacionId }, { preserveState: false });
}

const hora = (fecha: string | null): string => (fecha ?? '').slice(11, 16);

const iniciales = (nombre: string): string =>
    nombre.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();

const totalSinLeer = computed(() => props.conversaciones.reduce((t, c) => t + c.sin_leer, 0));
</script>

<template>
    <Head :title="`Chat · ${materia.nombre}`" />

    <AppLayout titulo="Chat de la materia">
        <section class="tarjeta p-6">
            <BotonVolver :href="volver.href" :texto="volver.texto" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-contenido">{{ materia.nombre }}</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        <span v-if="totalSinLeer">{{ totalSinLeer }} sin leer</span>
                        <span v-else>Al día</span>
                    </p>
                </div>

                <button
                    v-if="contactos.length && materia.abierta"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    @click="eligiendo = !eligiendo"
                >
                    {{ eligiendo ? 'Cancelar' : (soy_docente ? 'Escribirle a un alumno' : 'Escribirle al docente') }}
                </button>
            </div>

            <p
                v-if="!materia.abierta"
                class="mt-4 rounded-lg px-3 py-2 text-sm"
                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)' }"
            >
                Esta materia ya cerró: el chat queda solo de lectura.
            </p>

            <ul v-if="eligiendo" class="mt-4 divide-y divide-borde rounded-lg border border-borde">
                <li v-for="c in contactos" :key="c.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition hover:bg-[color-mix(in_srgb,var(--color-acento)_6%,transparent)]"
                        @click="abrirDirecta(c.id)"
                    >
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                        >
                            {{ iniciales(c.nombre) }}
                        </span>
                        {{ c.nombre }}
                    </button>
                </li>
            </ul>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="grid gap-0 md:grid-cols-[240px_1fr]">
                <!-- Conversaciones -->
                <aside class="border-borde md:border-r">
                    <ul class="divide-y divide-borde">
                        <li v-for="c in conversaciones" :key="c.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-4 py-3 text-left transition"
                                :style="{
                                    backgroundColor: c.id === abierta.id
                                        ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)'
                                        : 'transparent',
                                    borderLeft: c.id === abierta.id ? '3px solid var(--color-acento)' : '3px solid transparent',
                                }"
                                @click="irA(c.id)"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-contenido">{{ c.titulo }}</span>
                                    <span class="block text-[11px] text-suave">
                                        {{ c.tipo === 'grupo' ? 'Todos en la materia' : 'Conversación directa' }}
                                    </span>
                                </span>
                                <span
                                    v-if="c.sin_leer"
                                    class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold text-white"
                                    :style="{ backgroundColor: 'var(--color-acento)' }"
                                >
                                    {{ c.sin_leer }}
                                </span>
                            </button>
                        </li>
                    </ul>
                </aside>

                <!-- Hilo -->
                <div class="flex min-h-[28rem] flex-col">
                    <header class="border-b border-borde px-5 py-3">
                        <h3 class="text-sm font-semibold text-contenido">{{ abierta.titulo }}</h3>
                    </header>

                    <div ref="hilo" class="flex-1 space-y-3 overflow-y-auto px-5 py-4" style="max-height: 26rem">
                        <p v-if="!mensajes.length" class="py-10 text-center text-sm text-suave">
                            Todavía no hay mensajes. Escribe el primero.
                        </p>

                        <div
                            v-for="m in mensajes"
                            :key="m.id"
                            class="flex gap-2"
                            :class="m.persona_id === yo ? 'flex-row-reverse' : ''"
                        >
                            <span
                                v-if="m.persona_id !== yo"
                                class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 16%, transparent)', color: 'var(--color-suave)' }"
                            >
                                {{ iniciales(m.autor) }}
                            </span>

                            <span
                                class="max-w-[75%] rounded-2xl px-3 py-2"
                                :style="m.persona_id === yo
                                    ? { backgroundColor: 'var(--color-acento)', color: '#fff' }
                                    : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)' }"
                            >
                                <!-- En el canal del grupo hay que saber quién habla;
                                     en una directa sobra decirlo en cada burbuja. -->
                                <span
                                    v-if="abierta.tipo === 'grupo' && m.persona_id !== yo"
                                    class="mb-0.5 block text-[11px] font-semibold opacity-70"
                                >
                                    {{ m.autor }}
                                </span>
                                <span class="block whitespace-pre-line text-sm">{{ m.cuerpo }}</span>
                                <span class="mt-0.5 block text-right text-[10px] opacity-60">{{ hora(m.en) }}</span>
                            </span>
                        </div>
                    </div>

                    <form
                        v-if="materia.abierta"
                        class="flex items-end gap-2 border-t border-borde px-5 py-3"
                        @submit.prevent="enviar"
                    >
                        <textarea
                            v-model="form.cuerpo"
                            rows="2"
                            class="min-w-0 flex-1 resize-none rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            placeholder="Escribe un mensaje… (Enter manda, Shift+Enter salta de línea)"
                            @keydown="alTeclear"
                        />
                        <button
                            type="submit"
                            class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                            :style="{ backgroundColor: 'var(--color-acento)' }"
                            :disabled="form.processing || form.cuerpo.trim() === ''"
                        >
                            Enviar
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
