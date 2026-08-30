<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdjuntosDeAviso from '@/Components/AdjuntosDeAviso.vue';
import ContenidoRico from '@/Components/ContenidoRico.vue';
import type { AvisoRecibido } from '@/tipos';

/**
 * Los avisos de la persona, todos juntos.
 *
 * Es el sitio de los INFORMATIVOS —que no interrumpen a nadie— y el archivo de
 * los otros dos: el crítico que ya confirmó y el importante que cerró siguen
 * aquí. Un aviso que se atendió y desapareció sin dejar dónde volver a leerlo
 * obliga a recordar de memoria a qué hora era la junta.
 *
 * ── Cómo se ordena la lectura ──────────────────────────────────────────────
 * Lo pendiente arriba y lo atendido abajo, con separador. Sin esa división, un
 * aviso nuevo aparece entre diez ya leídos y se pierde justo el que había que
 * mirar. Dentro de cada bloque manda la urgencia, que es como llegan del
 * servidor.
 */
interface AvisoConLectura extends AvisoRecibido {
    confirmado: string | null;
}

/*
 * `lista` y no `avisos`: ese nombre es de la prop compartida que alimentan la
 * campana y el aviso bloqueante. Llamarla igual la pisaría justo en esta
 * pantalla. Ver el comentario de MisAvisosController::index.
 */
const props = defineProps<{ lista: AvisoConLectura[] }>();

const confirmando = ref<number | null>(null);

/** Le falta algo a esta persona: confirmarlo, si el aviso lo pide. */
function pendiente(aviso: AvisoConLectura): boolean {
    return aviso.prioridad !== 'informativo' && aviso.confirmado === null;
}

const porAtender = computed(() => props.lista.filter(pendiente));
const atendidos = computed(() => props.lista.filter((a) => ! pendiente(a)));

function confirmar(aviso: AvisoConLectura): void {
    confirmando.value = aviso.id;

    router.post(`/mis-avisos/${aviso.id}/confirmar`, {}, {
        preserveScroll: true,
        onFinish: () => (confirmando.value = null),
    });
}

/** «14 de agosto, 09:00» en vez del formato de la base. */
function cuando(fecha: string | null): string {
    if (fecha === null) return '';

    return new Date(fecha.replace(' ', 'T')).toLocaleString('es-MX', {
        day: 'numeric',
        month: 'long',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** El trazo del icono según la prioridad: campana, exclamación o información. */
function icono(prioridad: string): string {
    if (prioridad === 'critico') return 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z';
    if (prioridad === 'importante') return 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0';

    return 'M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z';
}
</script>

<template>
    <Head title="Mis avisos" />

    <AppLayout titulo="Mis avisos">
        <template v-if="lista.length">
            <section v-if="porAtender.length" class="mb-6 space-y-3">
                <h2 class="text-sm font-semibold text-contenido">
                    Por atender
                    <span class="ml-1 font-normal text-suave">
                        ({{ porAtender.length }})
                    </span>
                </h2>

                <article
                    v-for="a in porAtender"
                    :key="a.id"
                    class="tarjeta overflow-hidden"
                    :style="{ borderColor: `color-mix(in srgb, ${a.color} 35%, var(--color-borde))` }"
                >
                    <!-- Franja superior con el color de la prioridad: da el tono
                         de la tarjeta sin teñir el texto, que hay que leer. -->
                    <div class="h-1" :style="{ backgroundColor: a.color }" />

                    <div class="p-5">
                        <div class="flex items-start gap-3">
                            <span
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-xl"
                                :style="{ backgroundColor: `color-mix(in srgb, ${a.color} 12%, transparent)`, color: a.color }"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="icono(a.prioridad)" />
                                </svg>
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="font-medium" :style="{ color: a.color }">{{ a.prioridad_etiqueta }}</span>
                                    <span v-if="a.publicado_desde" class="text-suave">· {{ cuando(a.publicado_desde) }}</span>
                                    <span
                                        v-if="a.vigente_hasta"
                                        class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800"
                                    >
                                        Hasta el {{ cuando(a.vigente_hasta) }}
                                    </span>
                                </div>

                                <h3 class="mt-1 font-semibold text-contenido">{{ a.titulo }}</h3>
                                <ContenidoRico :html="a.cuerpo" class="mt-1.5" />
                                <AdjuntosDeAviso :adjuntos="a.adjuntos" />

                                <button
                                    type="button"
                                    class="mt-3 rounded-lg px-3.5 py-2 text-sm font-medium text-white transition disabled:opacity-60"
                                    :style="{ backgroundColor: a.color }"
                                    :disabled="confirmando === a.id"
                                    @click="confirmar(a)"
                                >
                                    {{ confirmando === a.id ? 'Registrando…' : 'Confirmo que lo leí' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <section v-if="atendidos.length" class="space-y-3">
                <h2 v-if="porAtender.length" class="text-sm font-semibold text-suave">Ya atendidos</h2>

                <article
                    v-for="a in atendidos"
                    :key="a.id"
                    class="tarjeta flex items-start gap-3 p-5"
                >
                    <span
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-xl"
                        :style="{ backgroundColor: `color-mix(in srgb, ${a.color} 10%, transparent)`, color: a.color }"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icono(a.prioridad)" />
                        </svg>
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-medium" :style="{ color: a.color }">{{ a.prioridad_etiqueta }}</span>
                            <span v-if="a.publicado_desde" class="text-suave">· {{ cuando(a.publicado_desde) }}</span>

                            <!-- La constancia, en sus propias palabras: es lo que
                                 la persona firmó y tiene derecho a ver. -->
                            <span v-if="a.confirmado" class="inline-flex items-center gap-1 text-emerald-700">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                                Confirmaste haberlo leído el {{ cuando(a.confirmado) }}
                            </span>
                        </div>

                        <h3 class="mt-1 font-semibold text-contenido">{{ a.titulo }}</h3>
                        <ContenidoRico :html="a.cuerpo" class="mt-1.5" />
                        <AdjuntosDeAviso :adjuntos="a.adjuntos" />
                    </div>
                </article>
            </section>
        </template>

        <div v-else class="tarjeta px-6 py-16 text-center">
            <svg class="mx-auto h-10 w-10 text-suave opacity-40" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
            <p class="mt-3 text-sm text-suave">
                No tienes avisos por ahora. Aquí aparecerán los que la escuela dirija a tu grupo, a tu
                programa académico o a ti.
            </p>
        </div>
    </AppLayout>
</template>
