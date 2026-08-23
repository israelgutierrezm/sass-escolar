<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';
import { colorPorPuntos, ESCALA_POR_DEFECTO, type Escala } from '@/utils/escalaCalificacion';
import { aEscalaDeLaActividad, type EvaluacionPorCriterio, type RubricaDeActividad } from '@/utils/rubrica';

/**
 * Calificar una entrega, con lo que el alumno mandó a la vista.
 *
 * ── Por qué un panel y no una franja al pie de la tabla ────────────────────
 * Antes el formulario aparecía DEBAJO del libro de calificaciones: con treinta
 * alumnos, tocar una casilla de la fila 4 abría un cuadro que quedaba dos
 * pantallas más abajo, y calificar era un vaivén de desplazamiento. Peor: no
 * mostraba los archivos que el alumno había subido, así que calificar una tarea
 * entregada como PDF era calificar a ciegas.
 *
 * El panel se ancla al costado, no se mueve, y trae los tres datos que se
 * necesitan para poner una nota: qué entregó, cuándo, y sobre cuánto va.
 *
 * ── Con rúbrica no se escribe la nota: se elige un nivel por criterio ─────
 * La cifra sale de la suma y no se puede teclear encima, porque entonces habría
 * dos verdades sobre la misma entrega —el número y el desglose— sin forma de
 * saber cuál manda. Y la suma se lleva a los puntos de la ACTIVIDAD: una rúbrica
 * de 20 sobre una actividad de 10 da 8.5, no 17, que es lo que permite reusar la
 * misma rúbrica en trabajos de distinto peso.
 *
 * Un criterio sin elegir NO es un cero: lo evaluado se guarda y la entrega queda
 * sin calificar, igual que un componente sin capturar en el acta.
 *
 * ── Lo que se califica solo ────────────────────────────────────────────────
 * Un examen de opción múltiple lo califica la máquina en el momento en que el
 * alumno lo entrega. Aquí no se pisa esa nota con un campo de texto: se dice que
 * fue automática y se manda a la pantalla del examen, que es donde se revisan
 * las preguntas abiertas y donde recalcular tiene sentido. Escribir un número
 * encima habría dejado la calificación y las respuestas contando cosas
 * distintas.
 */
interface Archivo {
    id: number;
    nombre: string;
    bytes: number;
}

interface Casilla {
    actividad_id: number;
    entrega_id: number | null;
    estado: string;
    tarde: boolean;
    calificacion: number | null;
    retroalimentacion: string | null;
    contenido: string | null;
    entregada_en: string | null;
    automatica: boolean;
    por_rubrica: EvaluacionPorCriterio[];
    archivos: Archivo[];
    /** Las piezas del portafolio, si la actividad lo es. */
    evidencias?: {
        id: number;
        titulo: string;
        descripcion: string | null;
        fecha: string | null;
        archivos: { id: number; nombre: string; peso: string | null }[];
    }[];
}

interface Actividad {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    titulo: string;
    puntos: number;
    componente: string | null;
    /** Con qué se califica. Null = un número a mano, como siempre. */
    rubrica: RubricaDeActividad | null;
}

const props = defineProps<{
    materiaId: number;
    alumno: string | null;
    matricula: string | null;
    casilla: Casilla;
    actividad: Actividad;
    /** Cuántas entregas quedan sin calificar, para el botón de continuar. */
    pendientes: number;
    /** Con qué califica el plan de esta materia. Decide el color de la nota. */
    escala?: Escala;
}>();

const emit = defineEmits<{ cerrar: []; siguiente: [] }>();

const form = useForm({ calificacion: '' as string | number, retroalimentacion: '' });

/*
 * Al saltar de una entrega a otra el formulario se rehace con lo de la nueva.
 * Sin esto, «guardar y siguiente» arrastraba la retroalimentación del alumno
 * anterior al campo del siguiente, y basta un despiste para publicarla.
 */
watch(
    () => props.casilla,
    (c) => {
        form.clearErrors();
        form.calificacion = c.calificacion ?? '';
        form.retroalimentacion = c.retroalimentacion ?? '';
    },
    { immediate: true },
);

const esExamen = computed(() => props.actividad.tipo === 'examen');
const conRubrica = computed(() => props.actividad.rubrica !== null);

/** Nivel elegido y comentario por criterio. Se rehace al saltar de entrega. */
const porCriterio = ref<Record<number, { nivel_id: number | null; comentario: string }>>({});

watch(
    () => [props.casilla, props.actividad.rubrica] as const,
    ([casilla, rubrica]) => {
        const previo: Record<number, { nivel_id: number | null; comentario: string }> = {};

        for (const c of rubrica?.criterios ?? []) {
            const hecho = casilla.por_rubrica.find((e) => e.criterio_id === c.id);

            previo[c.id] = { nivel_id: hecho?.nivel_id ?? null, comentario: hecho?.comentario ?? '' };
        }

        porCriterio.value = previo;
    },
    { immediate: true },
);

function elegir(criterioId: number, nivelId: number): void {
    const actual = porCriterio.value[criterioId];

    // Volver a tocar el nivel elegido lo QUITA. Sin esto no habría forma de
    // deshacer un clic accidental salvo cerrar sin guardar.
    actual.nivel_id = actual.nivel_id === nivelId ? null : nivelId;
}

/** Lo que se lleva sumado de la rúbrica. */
const obtenido = computed(() => {
    let suma = 0;

    for (const c of props.actividad.rubrica?.criterios ?? []) {
        const nivel = c.niveles.find((n) => n.id === porCriterio.value[c.id]?.nivel_id);

        suma += nivel?.puntos ?? 0;
    }

    return Math.round(suma * 100) / 100;
});

const criteriosSinEvaluar = computed(
    () => (props.actividad.rubrica?.criterios ?? []).filter((c) => porCriterio.value[c.id]?.nivel_id == null).length,
);

/**
 * La nota que va a quedar. Se enseña ANTES de guardar porque es la decisión:
 * el docente elige niveles, no cifras, y necesita ver a qué equivalen.
 *
 * La cuenta buena la hace el servidor —ésta es sólo para mirarla—.
 */
const notaDeLaRubrica = computed(() =>
    aEscalaDeLaActividad(obtenido.value, props.actividad.rubrica?.total ?? 0, props.actividad.puntos),
);

/** Sin nota y ya entregada: es a lo que el docente vino. */
const esperaRevision = computed(
    () => props.casilla.entrega_id !== null && props.casilla.calificacion === null,
);

const seguirDespues = ref(true);

const formRubrica = useForm({
    criterios: [] as { criterio_id: number; nivel_id: number | null; comentario: string | null }[],
    retroalimentacion: '',
});

function guardarConRubrica(): void {
    formRubrica.criterios = (props.actividad.rubrica?.criterios ?? []).map((c) => ({
        criterio_id: c.id,
        nivel_id: porCriterio.value[c.id]?.nivel_id ?? null,
        comentario: porCriterio.value[c.id]?.comentario || null,
    }));
    formRubrica.retroalimentacion = form.retroalimentacion;

    formRubrica.put(`/docencia/materias/${props.materiaId}/entregas/${props.casilla.entrega_id}/calificar`, {
        preserveScroll: true,
        onSuccess: () => {
            // Sólo se salta a la siguiente si ésta quedó cerrada: con un
            // criterio en blanco la entrega sigue sin calificar y dejarla atrás
            // sería perderla de vista.
            if (seguirDespues.value && criteriosSinEvaluar.value === 0 && props.pendientes > 1) {
                emit('siguiente');

                return;
            }

            emit('cerrar');
        },
    });
}

function guardar(): void {
    if (conRubrica.value) {
        guardarConRubrica();

        return;
    }

    form.put(`/docencia/materias/${props.materiaId}/entregas/${props.casilla.entrega_id}/calificar`, {
        preserveScroll: true,
        onSuccess: () => {
            if (seguirDespues.value && props.pendientes > 1) {
                emit('siguiente');

                return;
            }

            emit('cerrar');
        },
    });
}

/** El máximo de un tirón: es la nota más puesta y evita teclear el número. */
function ponerMaximo(): void {
    form.calificacion = props.actividad.puntos;
}

function pesoLegible(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

const colorNota = computed(() => {
    // Con rúbrica la cifra no la teclea nadie: sale de los niveles elegidos, y
    // mientras falte alguno no hay nota que colorear.
    if (conRubrica.value) {
        if (criteriosSinEvaluar.value > 0) return 'var(--color-suave)';

        return colorPorPuntos(notaDeLaRubrica.value, props.actividad.puntos, props.escala ?? ESCALA_POR_DEFECTO);
    }

    const n = Number(form.calificacion);

    if (form.calificacion === '' || Number.isNaN(n)) return 'var(--color-suave)';

    // Los puntos de la actividad, llevados a la escala del plan: el color
    // tiene que significar lo mismo aquí que en el acta.
    return colorPorPuntos(n, props.actividad.puntos, props.escala ?? ESCALA_POR_DEFECTO);
});
</script>

<template>
    <!-- Fondo: atenúa la rejilla para que se lea el trabajo, y cierra al tocar
         fuera, que es lo que uno intenta antes de buscar el botón. -->
    <div class="fixed inset-0 z-40 bg-black/25" @click="emit('cerrar')" />

    <aside
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col shadow-2xl"
        :style="{ backgroundColor: 'var(--color-superficie)' }"
    >
        <!-- Quién y qué -->
        <header class="flex items-start gap-3 border-b border-borde px-5 py-4">
            <span class="min-w-0 flex-1">
                <span class="block truncate font-semibold text-contenido">{{ alumno }}</span>
                <span class="block truncate font-mono text-xs text-suave">{{ matricula }}</span>
                <span class="mt-1.5 flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                    >
                        {{ actividad.tipo_etiqueta }}
                    </span>
                    <span class="truncate text-sm text-contenido">{{ actividad.titulo }}</span>
                </span>
                <span v-if="actividad.componente" class="mt-0.5 block text-[11px] text-suave">
                    {{ actividad.componente }} · sobre {{ actividad.puntos }} puntos
                </span>
                <span v-if="actividad.rubrica" class="mt-0.5 block text-[11px] text-suave">
                    Se califica con «{{ actividad.rubrica.nombre }}»
                </span>
            </span>

            <button
                type="button"
                class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-suave transition hover:bg-[color-mix(in_srgb,var(--color-suave)_12%,transparent)]"
                title="Cerrar"
                @click="emit('cerrar')"
            >
                ✕
            </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <!-- Cuándo llegó -->
            <div class="flex flex-wrap items-center gap-2 border-b border-borde px-5 py-3 text-xs">
                <span class="text-suave">Entregó el {{ casilla.entregada_en }}</span>
                <span
                    v-if="casilla.tarde"
                    class="rounded-full px-2 py-0.5 font-medium"
                    :style="{ backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                >
                    Fuera de tiempo
                </span>
                <span
                    v-if="casilla.automatica"
                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium"
                    :style="{ backgroundColor: 'color-mix(in srgb, #2563eb 12%, transparent)', color: '#1d4ed8' }"
                    title="La calificó el sistema al momento de entregarse"
                >
                    ⚡ Calificada automáticamente
                </span>
            </div>

            <!-- Qué entregó -->
            <section class="px-5 py-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-suave">Su trabajo</h3>

                <p
                    v-if="casilla.contenido"
                    class="mt-2 whitespace-pre-line rounded-lg px-3 py-2.5 text-sm text-contenido"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 7%, transparent)' }"
                >
                    {{ casilla.contenido }}
                </p>

                <!-- Los adjuntos: en la mayoría de las tareas SON la entrega. -->
                <ul v-if="casilla.archivos.length" class="mt-3 space-y-1.5">
                    <li v-for="f in casilla.archivos" :key="f.id">
                        <a
                            :href="`/mis-cursos/entregas/archivos/${f.id}`"
                            class="flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition hover:border-[var(--color-acento)]"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <svg class="h-4 w-4 shrink-0" :style="{ color: 'var(--color-acento)' }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.documento" />
                            </svg>
                            <span class="min-w-0 flex-1 truncate text-contenido">{{ f.nombre }}</span>
                            <span class="shrink-0 text-xs text-suave">{{ pesoLegible(f.bytes) }}</span>
                        </a>
                    </li>
                </ul>

                <!--
                    El portafolio, pieza por pieza. Es lo que hay que leer para
                    calificarlo: la descripción de cada evidencia ES el trabajo,
                    y sin ella el docente sólo vería «entregada».
                -->
                <ol v-if="casilla.evidencias?.length" class="mt-3 space-y-3">
                    <li
                        v-for="(ev, i) in casilla.evidencias"
                        :key="ev.id"
                        class="rounded-lg border px-3 py-2.5"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <h4 class="text-sm font-medium text-contenido">
                            <span class="text-suave">{{ i + 1 }}.</span> {{ ev.titulo }}
                        </h4>
                        <p v-if="ev.fecha" class="mt-0.5 text-xs text-suave">{{ ev.fecha }}</p>
                        <p v-if="ev.descripcion" class="mt-1 whitespace-pre-line text-sm text-contenido">
                            {{ ev.descripcion }}
                        </p>
                        <ul v-if="ev.archivos.length" class="mt-2 flex flex-wrap gap-2">
                            <li v-for="f in ev.archivos" :key="f.id">
                                <a
                                    :href="`/mis-cursos/portafolio/archivos/${f.id}`"
                                    class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs"
                                    :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                >
                                    {{ f.nombre }}
                                    <span v-if="f.peso" class="text-suave">{{ f.peso }}</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                </ol>

                <p
                    v-if="!casilla.contenido && !casilla.archivos.length && !casilla.evidencias?.length && !esExamen"
                    class="mt-2 text-sm text-suave"
                >
                    La entrega llegó vacía: sin texto ni archivos.
                </p>

                <!-- El examen no se lee aquí: se revisa en su pantalla, que es
                     donde están las respuestas pregunta por pregunta. -->
                <div
                    v-if="esExamen"
                    class="mt-2 rounded-lg px-4 py-3"
                    :style="{ backgroundColor: 'color-mix(in srgb, #2563eb 7%, transparent)' }"
                >
                    <p class="text-sm text-contenido">
                        <template v-if="esperaRevision">
                            Este examen tiene preguntas abiertas que la máquina no puede
                            calificar. Se revisan una por una y la nota se calcula sola al
                            terminar.
                        </template>
                        <template v-else>
                            La nota salió del examen. Puedes ver qué contestó y volver a
                            revisar las preguntas abiertas.
                        </template>
                    </p>
                    <a
                        :href="`/docencia/materias/${materiaId}/examenes/${actividad.id}`"
                        class="mt-2.5 inline-block rounded-lg px-3.5 py-1.5 text-xs font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    >
                        {{ esperaRevision ? 'Revisar el examen' : 'Ver el examen' }}
                    </a>
                </div>
            </section>

            <!-- ===== La rúbrica ===== -->
            <section v-if="actividad.rubrica" class="border-t border-borde px-5 py-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-suave">
                    Rúbrica — elige un nivel por criterio
                </h3>

                <div
                    v-for="c in actividad.rubrica.criterios"
                    :key="c.id"
                    class="mt-3 first:mt-2"
                >
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-medium text-contenido">{{ c.titulo }}</span>
                        <span class="shrink-0 text-xs text-suave">hasta {{ c.maximo }}</span>
                    </div>
                    <p v-if="c.descripcion" class="mt-0.5 text-xs text-suave">{{ c.descripcion }}</p>

                    <!-- Los niveles como botones y no como desplegable: el
                         descriptor es lo que se compara para decidir, y dentro
                         de un <select> no se puede leer más de uno a la vez. -->
                    <div class="mt-1.5 grid gap-1.5 sm:grid-cols-2">
                        <button
                            v-for="n in c.niveles"
                            :key="n.id"
                            type="button"
                            class="rounded-lg border px-2.5 py-2 text-left transition"
                            :style="{
                                borderColor: porCriterio[c.id]?.nivel_id === n.id
                                    ? 'var(--color-acento)'
                                    : 'var(--color-borde)',
                                backgroundColor: porCriterio[c.id]?.nivel_id === n.id
                                    ? 'color-mix(in srgb, var(--color-acento) 10%, transparent)'
                                    : 'transparent',
                            }"
                            @click="elegir(c.id, n.id)"
                        >
                            <span class="flex items-baseline justify-between gap-2">
                                <strong class="text-xs text-contenido">{{ n.titulo }}</strong>
                                <span
                                    class="text-xs font-semibold tabular-nums"
                                    :style="{ color: 'var(--color-acento)' }"
                                >{{ n.puntos }}</span>
                            </span>
                            <span v-if="n.descripcion" class="mt-0.5 block text-[11px] leading-snug text-suave">
                                {{ n.descripcion }}
                            </span>
                        </button>
                    </div>

                    <!-- Una nota de este criterio y no de la entrega entera: es
                         donde se explica por qué no se le dio el nivel de
                         arriba, que es lo que el alumno quiere saber. -->
                    <input
                        v-if="porCriterio[c.id]"
                        v-model="porCriterio[c.id].comentario"
                        type="text"
                        class="mt-1.5 w-full rounded-lg border px-2.5 py-1.5 text-xs"
                        :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                        placeholder="Nota de este criterio (opcional)"
                    />
                </div>
            </section>
        </div>

        <!-- Poner la nota -->
        <form
            v-if="!esExamen"
            class="border-t border-borde px-5 py-4"
            @submit.prevent="guardar"
        >
            <!-- Con rúbrica la nota NO se teclea: sale de los niveles. Un
                 campo editable aquí dejaría dos verdades sobre la misma
                 entrega y ninguna forma de saber cuál manda. -->
            <div v-if="conRubrica" class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="text-xs font-medium text-suave">Calificación</span>
                <span class="text-2xl font-semibold tabular-nums" :style="{ color: colorNota }">
                    {{ criteriosSinEvaluar === 0 ? notaDeLaRubrica : '—' }}
                </span>
                <span class="text-sm text-suave">de {{ actividad.puntos }}</span>
                <span class="text-xs text-suave">
                    · {{ obtenido }} de {{ actividad.rubrica?.total }} de la rúbrica
                </span>
            </div>

            <!-- Un criterio en blanco no es un cero: se dice antes de guardar,
                 no después. -->
            <p
                v-if="conRubrica && criteriosSinEvaluar > 0"
                class="mt-2 rounded-lg px-3 py-2 text-xs"
                :style="{ backgroundColor: 'color-mix(in srgb, #d97706 12%, transparent)', color: '#b45309' }"
            >
                Falta elegir nivel en {{ criteriosSinEvaluar }}
                {{ criteriosSinEvaluar === 1 ? 'criterio' : 'criterios' }}. Se guarda lo que llevas,
                pero la entrega no queda calificada: un criterio en blanco no cuenta como cero.
            </p>

            <div v-if="!conRubrica" class="flex items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-suave">Calificación</label>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="form.calificacion"
                            type="number"
                            step="0.01"
                            min="0"
                            :max="actividad.puntos"
                            class="w-24 rounded-lg border px-3 py-2 text-lg font-semibold"
                            :style="{ borderColor: 'var(--color-borde)', color: colorNota }"
                        />
                        <span class="text-sm text-suave">de {{ actividad.puntos }}</span>
                        <button
                            type="button"
                            class="rounded-lg border px-2.5 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                            title="Poner el puntaje completo"
                            @click="ponerMaximo"
                        >
                            Máximo
                        </button>
                    </div>
                    <p v-if="form.errors.calificacion" class="mt-1 text-xs text-red-600">
                        {{ form.errors.calificacion }}
                    </p>
                </div>
            </div>

            <div class="mt-3">
                <label class="mb-1 block text-xs font-medium text-suave">
                    Retroalimentación <span class="font-normal">— la lee el alumno</span>
                </label>
                <textarea
                    v-model="form.retroalimentacion"
                    rows="3"
                    class="w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    placeholder="Qué estuvo bien, qué corregir. Es lo único que se lleva de vuelta."
                />
                <p v-if="form.errors.retroalimentacion" class="mt-1 text-xs text-red-600">
                    {{ form.errors.retroalimentacion }}
                </p>
                <p v-if="formRubrica.errors.criterios" class="mt-1 text-xs text-red-600">
                    {{ formRubrica.errors.criterios }}
                </p>
            </div>

            <!-- Calificar es una tarea en serie: se hacen las treinta de un
                 tirón. Sin esto había que cerrar, buscar la siguiente casilla
                 naranja y volver a abrir. -->
            <label
                v-if="pendientes > 1"
                class="mt-3 flex items-center gap-2 text-xs text-suave"
            >
                <input v-model="seguirDespues" type="checkbox" />
                Al guardar, pasar a la siguiente sin calificar
                ({{ pendientes - 1 }} más)
            </label>

            <div class="mt-4 flex items-center gap-2">
                <BotonPrincipal
                    :procesando="form.processing || formRubrica.processing"
                    :texto="seguirDespues && pendientes > 1 && criteriosSinEvaluar === 0
                        ? 'Guardar y seguir'
                        : 'Guardar'"
                    icono="guardar"
                />
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="emit('cerrar')"
                >
                    Cerrar
                </button>
            </div>
        </form>
    </aside>
</template>
