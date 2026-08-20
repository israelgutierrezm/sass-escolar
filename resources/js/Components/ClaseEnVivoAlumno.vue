<script setup lang="ts">
import { computed } from 'vue';

/**
 * El aviso de clase en línea, del lado del alumno.
 *
 * ── El botón sólo aparece cuando de verdad lleva a algún lado ──────────────
 * El servidor manda `url` en null mientras la clase no está abierta, así que
 * aquí no hay que decidirlo: si hay enlace, se entra; si no, se dice cuándo.
 * Poner un botón desactivado que se ve igual que el bueno enseña a picarle y a
 * no confiar en él.
 *
 * ── Lo que viene también se anuncia ────────────────────────────────────────
 * No sólo la de ahora. Saber que el jueves hay clase a las 9 es la mitad del
 * valor, y es lo que hace que esta tarjeta se mire aunque hoy no haya nada.
 */
interface Clase {
    id: number;
    titulo: string;
    proveedor: string;
    inicio: string | null;
    fin: string | null;
    estado: string;
    abierta: boolean;
    url: string | null;
    /** Sólo las que la escuela hizo visibles. El servidor ya las filtró. */
    grabaciones: { id: number; tipo: string; peso: string | null }[];
}

const props = defineProps<{ clases: Clase[] }>();

const nombreDe: Record<string, string> = { zoom: 'Zoom', meet: 'Google Meet' };

/** La que está abierta ahora, si hay alguna. */
const enVivo = computed(() => props.clases.find((c) => c.abierta && c.url));

/** Lo que todavía no ocurre: se anuncia, sin botón. */
const siguientes = computed(() =>
    props.clases.filter((c) => c !== enVivo.value && !c.grabaciones.length),
);

/*
 * Las que ya pasaron y dejaron video. Van en su propio bloque y no mezcladas
 * con las próximas: son dos cosas distintas —«qué me toca» y «qué me perdí»— y
 * juntas obligan a leer la fecha de cada renglón para saber cuál es cuál.
 */
const grabadas = computed(() => props.clases.filter((c) => c.grabaciones.length > 0));

const nombreTipo: Record<string, string> = {
    video: 'Video',
    audio: 'Audio',
    chat: 'Chat',
    transcripcion: 'Transcripción',
};
</script>

<template>
    <section v-if="clases.length" class="tarjeta overflow-hidden">
        <!-- En vivo: es lo único que se hace ahora, así que ocupa el lugar
             grande y lleva el único botón de la tarjeta. -->
        <div
            v-if="enVivo"
            class="flex flex-wrap items-center gap-3 px-5 py-4"
            :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 8%, transparent)' }"
        >
            <span class="relative flex h-2.5 w-2.5 shrink-0">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" :style="{ backgroundColor: '#16a34a' }" />
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: '#16a34a' }" />
            </span>

            <span class="min-w-0 flex-1">
                <strong class="block truncate text-sm text-contenido">{{ enVivo.titulo }}</strong>
                <span class="text-xs text-suave">
                    {{ enVivo.inicio?.slice(11) }} a {{ enVivo.fin?.slice(11) }} ·
                    {{ nombreDe[enVivo.proveedor] ?? enVivo.proveedor }}
                </span>
            </span>

            <a
                :href="enVivo.url!"
                target="_blank"
                rel="noopener"
                class="rounded-lg px-4 py-2 text-sm font-semibold"
                :style="{ backgroundColor: '#16a34a', color: '#fff' }"
            >
                Entrar a la clase
            </a>
        </div>

        <div v-if="siguientes.length" class="px-5 py-4" :class="enVivo ? 'border-t border-borde' : ''">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-suave">
                {{ enVivo ? 'Después' : 'Próximas clases en línea' }}
            </h3>
            <ul class="mt-2 space-y-1.5">
                <li v-for="c in siguientes" :key="c.id" class="flex flex-wrap items-baseline gap-2 text-sm">
                    <span class="text-contenido">{{ c.titulo }}</span>
                    <span class="text-xs text-suave">
                        {{ c.inicio }} · {{ nombreDe[c.proveedor] ?? c.proveedor }}
                    </span>
                </li>
            </ul>
            <p class="mt-2 text-xs text-suave">
                El botón para entrar aparece aquí solo, unos minutos antes de que empiece.
            </p>
        </div>

        <!-- Clases grabadas: lo que busca quien faltó. -->
        <div v-if="grabadas.length" class="border-t border-borde px-5 py-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-suave">Clases grabadas</h3>
            <ul class="mt-2 space-y-2">
                <li v-for="c in grabadas" :key="c.id">
                    <span class="block text-sm text-contenido">{{ c.titulo }}</span>
                    <span class="text-xs text-suave">{{ c.inicio }}</span>
                    <span class="mt-1 flex flex-wrap gap-2">
                        <a
                            v-for="g in c.grabaciones"
                            :key="g.id"
                            :href="`/clases/grabaciones/${g.id}`"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                        >
                            {{ nombreTipo[g.tipo] ?? 'Archivo' }}
                            <span v-if="g.peso" class="text-suave">{{ g.peso }}</span>
                        </a>
                    </span>
                </li>
            </ul>
        </div>
    </section>
</template>
