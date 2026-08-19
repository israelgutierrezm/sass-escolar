<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PanelCalificacion from '@/Components/PanelCalificacion.vue';
import { colorPorPuntos, ESCALA_POR_DEFECTO, type Escala } from '@/utils/escalaCalificacion';
import type { EvaluacionPorCriterio, RubricaDeActividad } from '@/utils/rubrica';

/*
 * El libro de calificaciones: alumnos en filas, actividades en columnas.
 *
 * Es la pantalla que el docente abre todos los días, así que se parece a lo que
 * ya conoce de otros sistemas escolares: una rejilla donde cada casilla dice de
 * un vistazo si hay nota, si hay algo por revisar o si el alumno no entregó.
 *
 * ── La casilla dice el estado con su forma, no solo con su color ───────────
 * Un número es una calificación puesta. Un lápiz es «entregó y falta que lo
 * revises». Un círculo vacío es «no ha entregado». Distinguirlos solo por color
 * dejaría fuera a quien no los distingue, y obligaría a pasar el cursor por
 * cuarenta casillas para saber cuáles reclaman trabajo.
 *
 * ── La columna izquierda no se va ──────────────────────────────────────────
 * Nombre, matrícula, situación y asistencia quedan fijos al desplazarse a lo
 * ancho: con doce actividades, sin eso se acaba calificando a ciegas sin saber
 * de quién es el renglón.
 */
interface Casilla {
    actividad_id: number;
    entrega_id: number | null;
    estado: string;
    tarde: boolean;
    calificacion: number | null;
    retroalimentacion: string | null;
    contenido: string | null;
    entregada_en: string | null;
    /** La puso la máquina (un examen que se califica solo), no el docente. */
    automatica: boolean;
    /** Lo evaluado por criterio, si la actividad va con rúbrica. */
    por_rubrica: EvaluacionPorCriterio[];
    archivos: { id: number; nombre: string; bytes: number }[];
}

interface Fila {
    inscripcion_id: number;
    persona_id: number | null;
    matricula: string | null;
    nombre: string | null;
    situacion: string | null;
    de_baja: boolean;
    casillas: Casilla[];
}

interface ActividadColumna {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    se_entrega: boolean;
    titulo: string;
    puntos: number;
    componente: string | null;
    /** Con qué se califica. Null = un número a mano, como siempre. */
    rubrica: RubricaDeActividad | null;
    entregadas: number;
}

const props = defineProps<{
    materiaId: number;
    actividades: ActividadColumna[];
    matriz: Fila[];
    /** Del pase de lista: el acumulado de cada alumno, cruzado por inscripción. */
    asistencia: { inscripcion_id: number; porcentaje: number | null; faltas: number }[];
    /** Conversación directa con cada alumno, si ya existe: persona_id => …. */
    mensajes: Record<number, { conversacion_id: number; sin_leer: number }>;
    /** Con qué califica el plan de esta materia. Decide los colores. */
    escala?: Escala;
}>();

/*
 * Filtro por parcial. Con dos actividades no estorba; con veinte y cuarenta
 * alumnos son ochocientas casillas y un scroll horizontal que no termina. El
 * docente califica por parcial —es como cierra actas—, así que ese es el corte
 * natural. «Formativas» es su propio grupo: no cuelgan de ningún parcial y
 * esconderlas en «todas» las volvería invisibles justo cuando se buscan.
 */
const parcialFiltro = ref<string>('todos');

const parcialDe = (a: ActividadColumna): string =>
    a.componente ? a.componente.split(' · ')[0] : 'formativas';

const parcialesConActividad = computed(() => {
    const vistos = new Set<string>();

    for (const a of props.actividades) {
        if (a.se_entrega) vistos.add(parcialDe(a));
    }

    return [...vistos].sort();
});

const columnas = computed(() =>
    props.actividades.filter(
        (a) => a.se_entrega && (parcialFiltro.value === 'todos' || parcialDe(a) === parcialFiltro.value),
    ),
);

const idsColumna = computed(() => new Set(columnas.value.map((a) => a.id)));

const casillasDe = (fila: Fila): Casilla[] =>
    fila.casillas.filter((c) => idsColumna.value.has(c.actividad_id));

/* ── Lo que reclama trabajo ────────────────────────────────────────────── */

/** Entregas sin calificar: es a lo que el docente vino. */
const porRevisar = computed(() =>
    props.matriz.reduce(
        (t, f) => t + casillasDe(f).filter((c) => c.entrega_id !== null && c.calificacion === null).length,
        0,
    ),
);

/* ── Promedios ─────────────────────────────────────────────────────────── */

/**
 * El promedio del alumno sobre lo YA CALIFICADO, en escala de 10.
 *
 * No cuenta lo que aún no se revisa: si contara como cero, a media materia
 * todos aparecerían reprobados. Es el mismo criterio del cálculo por componente.
 */
function promedioDe(fila: Fila): number | null {
    const calificadas = casillasDe(fila).filter((c) => c.calificacion !== null);

    if (calificadas.length === 0) return null;

    const posibles = calificadas.reduce((t, c) => t + puntosDe(c.actividad_id), 0);

    if (posibles <= 0) return null;

    const obtenidos = calificadas.reduce((t, c) => t + Number(c.calificacion), 0);

    return Math.round((obtenidos * 10 / posibles) * 100) / 100;
}

const puntosDe = (actividadId: number): number =>
    props.actividades.find((a) => a.id === actividadId)?.puntos ?? 0;

/** El promedio del grupo en una actividad: delata la que salió mal a todos. */
function promedioGrupo(actividadId: number): number | null {
    const notas = props.matriz
        .filter((f) => !f.de_baja)
        .map((f) => f.casillas.find((c) => c.actividad_id === actividadId))
        .filter((c): c is Casilla => c?.calificacion != null)
        .map((c) => Number(c.calificacion));

    if (notas.length === 0) return null;

    return Math.round((notas.reduce((t, n) => t + n, 0) / notas.length) * 100) / 100;
}

const escalaViva = computed<Escala>(() => props.escala ?? ESCALA_POR_DEFECTO);

/** Los puntos de la casilla, coloreados con la escala del plan. */
function colorNota(nota: number | null, sobre: number): string {
    return colorPorPuntos(nota, sobre, escalaViva.value);
}

const asistenciaDe = (inscripcionId: number) =>
    props.asistencia.find((a) => a.inscripcion_id === inscripcionId);

function colorAsistencia(p: number | null | undefined): string {
    if (p === null || p === undefined) return 'var(--color-suave)';

    return p >= 90 ? '#16a34a' : p >= 80 ? '#d97706' : '#dc2626';
}

/* ── Calificar ─────────────────────────────────────────────────────────── */

const calificando = ref<{ inscripcion: number; actividad: number } | null>(null);

function abrirCalificacion(fila: Fila, c: Casilla): void {
    // Sin entrega no hay nada que calificar: poner nota a quien no entregó se
    // hace en la captura del parcial, no aquí.
    if (c.entrega_id === null) return;

    calificando.value = { inscripcion: fila.inscripcion_id, actividad: c.actividad_id };
}

/** La fila y la casilla que el panel está mostrando. */
const enPanel = computed(() => {
    if (calificando.value === null) return null;

    const fila = props.matriz.find((f) => f.inscripcion_id === calificando.value!.inscripcion);
    const casilla = fila?.casillas.find((c) => c.actividad_id === calificando.value!.actividad);
    const actividad = props.actividades.find((a) => a.id === calificando.value!.actividad);

    if (!fila || !casilla || !actividad) return null;

    return { fila, casilla, actividad };
});

/**
 * Lo que falta por calificar, en el orden en que se lee la rejilla: alumno por
 * alumno, y dentro de cada uno sus actividades.
 *
 * Es el recorrido de «guardar y seguir». Ordenarlo por actividad habría hecho
 * saltar de un alumno a otro y de vuelta, que es como se pierde el hilo de lo
 * que se venía revisando.
 */
const porCalificar = computed(() =>
    props.matriz.flatMap((f) =>
        casillasDe(f)
            .filter((c) => c.entrega_id !== null && c.calificacion === null)
            .map((c) => ({ inscripcion: f.inscripcion_id, actividad: c.actividad_id })),
    ),
);

/** Salta a la siguiente sin calificar; si no queda ninguna, cierra. */
function siguientePendiente(): void {
    const actual = calificando.value;

    const siguiente = porCalificar.value.find(
        (p) => p.inscripcion !== actual?.inscripcion || p.actividad !== actual?.actividad,
    );

    calificando.value = siguiente ?? null;
}

/* ── Escribirle a un alumno ────────────────────────────────────────────── */

/**
 * Abre el chat directo con ese alumno desde su propio renglón.
 *
 * Va por POST y no por enlace porque la conversación puede no existir todavía:
 * el servidor la crea si hace falta y redirige. Es lo que evita tener que ir al
 * chat, buscar el nombre en una lista de cuarenta y volver.
 *
 * El punto rojo dice quién escribió y espera respuesta —es lo que convierte la
 * columna en algo que se mira, y no en un enlace más—.
 */
function escribirle(fila: Fila): void {
    if (fila.persona_id === null) return;

    const abierta = props.mensajes[fila.persona_id];

    if (abierta) {
        router.get(`/materias/${props.materiaId}/chat`, { conversacion: abierta.conversacion_id });

        return;
    }

    router.post(`/materias/${props.materiaId}/chat/directa`, { persona_id: fila.persona_id });
}

const sinLeerDe = (fila: Fila): number =>
    fila.persona_id === null ? 0 : (props.mensajes[fila.persona_id]?.sin_leer ?? 0);

const totalSinLeer = computed(() =>
    props.matriz.reduce((t, f) => t + sinLeerDe(f), 0),
);

const abreviaturaTipo: Record<string, string> = {
    actividad: 'Act',
    examen: 'Exa',
    foro: 'Foro',
    lectura: 'Lec',
};
</script>

<template>
    <section class="tarjeta overflow-hidden">
        <header class="flex flex-wrap items-end justify-between gap-3 px-6 py-4">
            <div>
                <h2 class="text-base font-semibold text-contenido">Calificaciones</h2>
                <p class="mt-0.5 text-sm text-suave">
                    <template v-if="porRevisar">
                        <strong :style="{ color: '#d97706' }">{{ porRevisar }}</strong>
                        entrega(s) esperan tu revisión. Toca la casilla para calificar.
                    </template>
                    <template v-else>
                        Todo lo entregado está calificado. El componente del parcial se
                        recalcula solo.
                    </template>
                </p>
            </div>

            <div v-if="parcialesConActividad.length > 1" class="flex flex-wrap items-center gap-1">
                <button
                    v-for="p in ['todos', ...parcialesConActividad]"
                    :key="p"
                    type="button"
                    class="rounded-lg border px-2.5 py-1 text-xs font-medium capitalize"
                    :style="parcialFiltro === p
                        ? { borderColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }
                        : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                    @click="parcialFiltro = p"
                >
                    {{ p }}
                </button>
            </div>
        </header>

        <!-- Leyenda: la forma de la casilla dice el estado. -->
        <div class="flex flex-wrap items-center gap-4 border-t border-borde px-6 py-2 text-xs text-suave">
            <span class="flex items-center gap-1.5">
                <span class="font-semibold" :style="{ color: '#16a34a' }">8.5</span> calificada
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="flex h-5 w-5 items-center justify-center rounded"
                    :style="{ backgroundColor: 'color-mix(in srgb, #d97706 16%, transparent)', color: '#b45309' }"
                >✎</span>
                entregó, falta calificar
            </span>
            <span class="flex items-center gap-1.5">
                <span class="text-base leading-none text-suave">○</span> sin entregar
            </span>
            <span class="flex items-center gap-1.5">
                <span class="text-xs">⚡</span> la calificó el sistema
            </span>
        </div>

        <div class="overflow-x-auto border-t border-borde">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="text-[11px] uppercase tracking-wider"
                        :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }"
                    >
                        <th
                            class="sticky left-0 z-10 px-6 py-3 text-left font-semibold"
                            :style="{ backgroundColor: 'var(--color-superficie)' }"
                        >
                            Alumno
                        </th>
                        <th class="px-2 py-3 text-center font-semibold">
                            <span :title="totalSinLeer ? `${totalSinLeer} mensaje(s) sin leer` : 'Escribirle a un alumno'">
                                Mensaje
                            </span>
                        </th>
                        <th class="px-3 py-3 text-center font-semibold">Asist.</th>

                        <th
                            v-for="a in columnas"
                            :key="a.id"
                            class="px-3 py-3 text-center font-semibold"
                        >
                            <span class="block text-[10px] font-normal opacity-70">
                                {{ abreviaturaTipo[a.tipo] ?? a.tipo }}
                            </span>
                            <span class="block max-w-28 truncate normal-case" :title="a.titulo">
                                {{ a.titulo }}
                            </span>
                            <span class="block font-normal normal-case">de {{ a.puntos }}</span>
                        </th>

                        <th class="px-3 py-3 text-center font-semibold">Promedio</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="fila in matriz"
                        :key="fila.inscripcion_id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)', opacity: fila.de_baja ? 0.5 : 1 }"
                    >
                        <td
                            class="sticky left-0 z-10 px-6 py-2"
                            :style="{ backgroundColor: 'var(--color-superficie)' }"
                        >
                            <span class="block truncate">{{ fila.nombre }}</span>
                            <span class="block font-mono text-xs text-suave">
                                {{ fila.matricula }}
                                <span v-if="fila.situacion" class="font-sans">· {{ fila.situacion }}</span>
                            </span>
                        </td>

                        <!-- Escribirle sin salir del libro: al ver una nota baja
                             o una entrega que falta, preguntarle es lo siguiente
                             que uno quiere hacer. -->
                        <td class="px-2 py-2 text-center">
                            <button
                                type="button"
                                class="relative mx-auto flex h-8 w-8 items-center justify-center rounded-lg transition hover:bg-[color-mix(in_srgb,var(--color-acento)_10%,transparent)]"
                                :style="{ color: sinLeerDe(fila) ? 'var(--color-acento)' : 'var(--color-suave)' }"
                                :title="sinLeerDe(fila)
                                    ? `${sinLeerDe(fila)} mensaje(s) sin leer de ${fila.nombre}`
                                    : `Escribirle a ${fila.nombre}`"
                                :disabled="fila.persona_id === null"
                                @click="escribirle(fila)"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4.5 w-4.5" style="width: 18px; height: 18px">
                                    <rect x="2.5" y="4.5" width="19" height="15" rx="2" />
                                    <path d="m3 6 9 6 9-6" />
                                </svg>
                                <span
                                    v-if="sinLeerDe(fila)"
                                    class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold text-white"
                                    :style="{ backgroundColor: '#dc2626' }"
                                >
                                    {{ sinLeerDe(fila) }}
                                </span>
                            </button>
                        </td>

                        <!-- La asistencia junto a las notas: es la otra mitad de
                             cómo va el alumno, y tenerla en otra pestaña obligaba
                             a cruzarlas de memoria. -->
                        <td class="px-3 py-2 text-center text-xs">
                            <span :style="{ color: colorAsistencia(asistenciaDe(fila.inscripcion_id)?.porcentaje) }">
                                {{ asistenciaDe(fila.inscripcion_id)?.porcentaje ?? '—' }}<span
                                    v-if="asistenciaDe(fila.inscripcion_id)?.porcentaje != null"
                                >%</span>
                            </span>
                        </td>

                        <td
                            v-for="c in casillasDe(fila)"
                            :key="c.actividad_id"
                            class="px-2 py-1.5 text-center"
                        >
                            <button
                                type="button"
                                class="mx-auto flex h-8 w-full max-w-20 items-center justify-center rounded-lg text-sm font-semibold transition disabled:cursor-default"
                                :style="c.calificacion !== null
                                    ? { color: colorNota(Number(c.calificacion), puntosDe(c.actividad_id)) }
                                    : c.entrega_id !== null
                                        ? { backgroundColor: 'color-mix(in srgb, #d97706 16%, transparent)', color: '#b45309' }
                                        : { color: 'var(--color-suave)' }"
                                :disabled="c.entrega_id === null"
                                :title="c.entrega_id === null
                                    ? 'Sin entregar'
                                    : c.calificacion !== null
                                        ? `${c.calificacion} de ${puntosDe(c.actividad_id)} · entregó el ${c.entregada_en}${c.automatica ? ' · la calificó el sistema' : ''}`
                                        : `Entregó el ${c.entregada_en}${c.tarde ? ' (tarde)' : ''} · falta calificar`"
                                @click="abrirCalificacion(fila, c)"
                            >
                                <template v-if="c.calificacion !== null">
                                    {{ c.calificacion }}
                                    <!-- El rayo dice que la puso la máquina: al
                                         reclamar una nota, quién la puso es lo
                                         primero que hay que saber. -->
                                    <span v-if="c.automatica" class="ml-0.5 text-[10px] leading-none opacity-70" aria-hidden="true">⚡</span>
                                </template>
                                <template v-else-if="c.entrega_id !== null">✎</template>
                                <template v-else>○</template>
                            </button>
                        </td>

                        <td class="px-3 py-2 text-center">
                            <span
                                class="text-sm font-semibold"
                                :style="{ color: colorNota(promedioDe(fila), 10) }"
                            >
                                {{ promedioDe(fila) ?? '—' }}
                            </span>
                        </td>
                    </tr>
                </tbody>

                <!-- El promedio del grupo por actividad: delata la que le salió
                     mal a todos, que es una señal sobre la actividad y no sobre
                     los alumnos. -->
                <tfoot>
                    <tr
                        class="border-t-2 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }"
                    >
                        <td
                            class="sticky left-0 z-10 px-6 py-2 font-semibold"
                            :style="{ backgroundColor: 'var(--color-superficie)' }"
                        >
                            Promedio del grupo
                        </td>
                        <td />
                        <td />
                        <td v-for="a in columnas" :key="a.id" class="px-3 py-2 text-center font-semibold">
                            <span :style="{ color: colorNota(promedioGrupo(a.id), a.puntos) }">
                                {{ promedioGrupo(a.id) ?? '—' }}
                            </span>
                        </td>
                        <td />
                    </tr>
                </tfoot>
            </table>
        </div>

    </section>

    <!-- Calificar: en un panel al costado, con el trabajo del alumno a la vista.
         Fuera de la <section> para que no lo recorte el `overflow-hidden` de la
         tarjeta. -->
    <PanelCalificacion
        v-if="enPanel"
        :materia-id="materiaId"
        :alumno="enPanel.fila.nombre"
        :matricula="enPanel.fila.matricula"
        :casilla="enPanel.casilla"
        :actividad="enPanel.actividad"
        :pendientes="porCalificar.length"
        :escala="escalaViva"
        @cerrar="calificando = null"
        @siguiente="siguientePendiente"
    />
</template>
