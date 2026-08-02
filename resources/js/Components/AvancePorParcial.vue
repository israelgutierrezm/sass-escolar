<script setup lang="ts">
import { computed } from 'vue';

/*
 * El avance del alumno en la materia, parcial por parcial.
 *
 * ── Por qué por parcial y no una barra sola ────────────────────────────────
 * «Llevas 40%» no dice si va bien o mal: puede ser que el primer parcial esté
 * completo y el segundo sin empezar —normal a mitad de curso— o que lleve dos
 * parciales a medias —que es un problema—. El parcial es además la unidad con
 * la que se cierra el acta, así que es el corte en el que el alumno ya piensa.
 *
 * ── Dos cosas distintas que no hay que mezclar ─────────────────────────────
 * Lo ENTREGADO es lo que él controla; lo CALIFICADO depende del docente. Un
 * parcial con todo entregado y nada calificado no es culpa suya, y una barra
 * única que los sumara le echaría encima una espera ajena.
 */
interface ActividadAvance {
    id: number;
    se_entrega: boolean;
    componente: string | null;
    entrega: { entregada_en: string | null; calificacion: number | null } | null;
}

interface ParcialEvaluado {
    parcial: number;
    peso_total: number;
    peso_capturado: number;
    ganado: number;
    completo: boolean;
}

const props = defineProps<{
    actividades: ActividadAvance[];
    parciales: ParcialEvaluado[];
}>();

/** «Parcial 1 · examen_p1» → 1. Sin componente, es formativa y no cuenta aquí. */
function parcialDe(a: ActividadAvance): number | null {
    const encontrado = /Parcial\s+(\d+)/i.exec(a.componente ?? '');

    return encontrado ? Number(encontrado[1]) : null;
}

const bloques = computed(() => {
    // Los parciales que existen: los del esquema de evaluación, más cualquiera
    // que tenga actividades aunque todavía no se capture nada.
    const numeros = new Set<number>(props.parciales.map((p) => p.parcial));

    for (const a of props.actividades) {
        const n = parcialDe(a);
        if (n !== null) numeros.add(n);
    }

    return [...numeros].sort((a, b) => a - b).map((numero) => {
        const suyas = props.actividades.filter((a) => a.se_entrega && parcialDe(a) === numero);
        const entregadas = suyas.filter((a) => a.entrega?.entregada_en).length;
        /*
         * Lo calificado se cuenta sobre SUS ENTREGAS y no sobre el componente
         * del acta. El componente solo se llena cuando el docente cierra el
         * parcial, así que mirarlo hacía decir «sin calificar» de una tarea que
         * el alumno ya tenía con un 9 en la lista de abajo: dos pantallas
         * contradiciéndose.
         */
        const calificadas = suyas.filter((a) => a.entrega?.calificacion != null).length;
        const evaluado = props.parciales.find((p) => p.parcial === numero);

        return {
            numero,
            total: suyas.length,
            entregadas,
            calificadas,
            // Sin actividades cargadas no hay avance que medir; se dice, en vez
            // de pintar un 0% que parecería un descuido del alumno.
            porcentaje: suyas.length === 0 ? null : Math.round((entregadas * 100) / suyas.length),
            faltan: suyas.length - entregadas,
            ganado: evaluado?.ganado ?? null,
            pesoCapturado: evaluado?.peso_capturado ?? 0,
            pesoTotal: evaluado?.peso_total ?? 0,
        };
    });
});

/**
 * En cuál va: el primero que no está terminado.
 *
 * Es lo que decide el «estás aquí» del recorrido. Si todos están completos, no
 * hay ninguno en curso y se muestran todos como cerrados.
 */
const enCurso = computed(() => {
    const pendiente = bloques.value.find((b) => b.total > 0 && b.entregadas < b.total);

    return pendiente?.numero ?? null;
});

function estadoDe(b: { numero: number; total: number; entregadas: number }): 'completo' | 'actual' | 'pendiente' {
    if (b.total > 0 && b.entregadas === b.total) return 'completo';
    if (b.numero === enCurso.value) return 'actual';

    return 'pendiente';
}

const COLORES = {
    completo: '#16a34a',
    actual: 'var(--color-acento)',
    pendiente: 'var(--color-suave)',
} as const;

/** El avance de toda la materia: entregado sobre lo que hay que entregar. */
const global = computed(() => {
    const conEntrega = props.actividades.filter((a) => a.se_entrega);

    if (conEntrega.length === 0) return null;

    const hechas = conEntrega.filter((a) => a.entrega?.entregada_en).length;

    return { hechas, total: conEntrega.length, porcentaje: Math.round((hechas * 100) / conEntrega.length) };
});
</script>

<template>
    <section v-if="bloques.length" class="tarjeta p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-contenido">Tu avance</h2>
                <p v-if="global" class="mt-0.5 text-sm text-suave">
                    Entregaste {{ global.hechas }} de {{ global.total }} actividades.
                </p>
            </div>

            <span
                v-if="global"
                class="text-2xl font-semibold leading-none"
                :style="{ color: global.porcentaje === 100 ? '#16a34a' : 'var(--color-acento)' }"
            >
                {{ global.porcentaje }}%
            </span>
        </div>

        <!-- La barra de toda la materia, arriba: el resumen antes del detalle. -->
        <div
            v-if="global"
            class="mt-3 h-2 w-full overflow-hidden rounded-full"
            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 16%, transparent)' }"
        >
            <div
                class="h-full rounded-full transition-all"
                :style="{
                    width: `${global.porcentaje}%`,
                    backgroundColor: global.porcentaje === 100 ? '#16a34a' : 'var(--color-acento)',
                }"
            />
        </div>

        <!--
            El recorrido por parciales. Cada nodo dice en qué punto va: palomita
            si ya cerró, relleno si es el de ahora, hueco si no ha empezado. La
            forma dice el estado, no solo el color.
        -->
        <ol class="mt-6 space-y-5 md:flex md:space-x-0 md:space-y-0">
            <li
                v-for="(b, i) in bloques"
                :key="b.numero"
                class="relative flex gap-3 md:flex-1 md:flex-col md:gap-2"
            >
                <!-- La línea que une los parciales: hace ver un recorrido y no
                     cuatro tarjetas sueltas. -->
                <span
                    v-if="i < bloques.length - 1"
                    class="absolute hidden md:block"
                    :style="{
                        left: 'calc(50% + 1rem)',
                        right: 'calc(-50% + 1rem)',
                        top: '0.9rem',
                        height: '2px',
                        backgroundColor: estadoDe(bloques[i + 1]) === 'pendiente'
                            ? 'color-mix(in srgb, var(--color-suave) 25%, transparent)'
                            : COLORES[estadoDe(b)],
                    }"
                />
                <span
                    v-if="i < bloques.length - 1"
                    class="absolute left-3.5 top-8 w-0.5 md:hidden"
                    :style="{
                        height: 'calc(100% + 0.5rem)',
                        backgroundColor: 'color-mix(in srgb, var(--color-suave) 25%, transparent)',
                    }"
                />

                <span
                    class="relative z-10 grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-semibold md:mx-auto"
                    :style="estadoDe(b) === 'pendiente'
                        ? { border: '2px solid color-mix(in srgb, var(--color-suave) 35%, transparent)', color: 'var(--color-suave)', backgroundColor: 'var(--color-superficie)' }
                        : { backgroundColor: COLORES[estadoDe(b)], color: '#fff' }"
                >
                    <template v-if="estadoDe(b) === 'completo'">✓</template>
                    <template v-else>{{ b.numero }}</template>
                </span>

                <span class="min-w-0 flex-1 md:text-center">
                    <span
                        class="block text-sm font-medium"
                        :style="{ color: estadoDe(b) === 'pendiente' ? 'var(--color-suave)' : 'var(--color-contenido)' }"
                    >
                        Parcial {{ b.numero }}
                    </span>

                    <span v-if="b.total === 0" class="block text-xs text-suave">
                        Sin actividades todavía
                    </span>

                    <template v-else>
                        <span class="block text-xs text-suave">
                            {{ b.entregadas }} de {{ b.total }} entregadas
                        </span>

                        <span
                            class="mx-auto mt-1.5 block h-1.5 w-full max-w-40 overflow-hidden rounded-full md:max-w-28"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 16%, transparent)' }"
                        >
                            <span
                                class="block h-full rounded-full transition-all"
                                :style="{ width: `${b.porcentaje}%`, backgroundColor: COLORES[estadoDe(b)] }"
                            />
                        </span>

                        <!-- Lo calificado va aparte de lo entregado: lo primero
                             depende del docente y lo segundo de él. -->
                        <span v-if="b.pesoCapturado > 0" class="mt-1 block text-xs text-suave">
                            Llevas <strong :style="{ color: 'var(--color-contenido)' }">{{ b.ganado }}</strong>
                            de {{ b.pesoCapturado }} pts calificados
                        </span>
                        <span v-else-if="b.calificadas > 0" class="mt-1 block text-xs text-suave">
                            {{ b.calificadas }} de {{ b.entregadas }} calificadas
                        </span>
                        <span v-else-if="b.entregadas > 0" class="mt-1 block text-xs text-suave">
                            Tu docente aún no las revisa
                        </span>
                    </template>
                </span>
            </li>
        </ol>
    </section>
</template>
