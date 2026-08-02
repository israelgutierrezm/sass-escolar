<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CuandoVence from '@/Components/CuandoVence.vue';
import IndiceDelCurso from '@/Components/IndiceDelCurso.vue';
import ZonaArchivos from '@/Components/ZonaArchivos.vue';
import { ICONOS } from '@/iconos';

/**
 * EL AULA — la materia recorrida como un libro.
 *
 * ── Qué problema resuelve ──────────────────────────────────────────────────
 * «Mi materia» contesta cómo voy: calificaciones, asistencia, quién imparte. No
 * contesta qué sigue. Con contenido de verdad —una lección con su texto, su
 * video incrustado, su ejercicio— una lista de tarjetas con píldoras de estado
 * ya no alcanza: para leer hace falta una columna ancha, silencio alrededor y un
 * solo siguiente paso a la vista.
 *
 * ── Cómo está armada ───────────────────────────────────────────────────────
 * Índice a la izquierda, lección a la derecha, progreso arriba y en el índice.
 * Es la forma que todo el mundo ya sabe usar porque la usan las plataformas de
 * cursos; lo que se le añade es lo que una escuela sí necesita y aquéllas no
 * tienen: cuánto pesa la lección en la calificación, a qué parcial va, cuándo
 * vence y qué te contestó el docente.
 *
 * Lo que se entrega se entrega AQUÍ mismo, sin salir de la lección. Mandar al
 * alumno a otra pantalla para subir un archivo rompe el hilo de lo que estaba
 * leyendo.
 */
interface Entrega {
    id: number;
    estado: string;
    contenido: string | null;
    entregada_en: string | null;
    tarde: boolean;
    calificacion: number | null;
    retroalimentacion: string | null;
    archivos: { id: number; nombre: string }[];
}

interface Leccion {
    id: number;
    numero: number;
    tipo: string;
    tipo_etiqueta: string;
    se_entrega: boolean;
    titulo: string;
    instrucciones: string | null;
    contenido: string | null;
    tiene_contenido: boolean;
    puntos: number;
    abre_en: string | null;
    cierra_en: string | null;
    dias: number | null;
    permite_tarde: boolean;
    /** Si puede reemplazar lo entregado. Falso = una sola oportunidad. */
    permite_reentrega: boolean;
    abierta: boolean;
    parcial: number | null;
    componente: string | null;
    completada: boolean;
    visitada: boolean;
    entrega: Entrega | null;
}

interface Unidad {
    clave: number;
    nombre: string;
    lecciones: Leccion[];
    completadas: number;
    total: number;
}

const props = defineProps<{
    curso: {
        id: number;
        materia: string | null;
        clave: string | null;
        grupo: string | null;
        ciclo: string | null;
        presentacion: string | null;
        docente: string | null;
    };
    unidades: Unidad[];
    leccion: Leccion | null;
    vecinas: {
        anterior: { id: number; titulo: string; tipo: string } | null;
        siguiente: { id: number; titulo: string; tipo: string } | null;
    };
    progreso: { total: number; completadas: number; porcentaje: number; pendientes: number };
}>();

const iconoDe: Record<string, string> = {
    lectura: ICONOS.documentoTexto,
    actividad: ICONOS.tareaCheck,
    foro: ICONOS.burbujas,
    examen: ICONOS.escudo,
};

/** En qué unidad vive la lección abierta, para las migas de arriba. */
const unidadActual = computed(() =>
    props.unidades.find((u) => u.lecciones.some((l) => l.id === props.leccion?.id)),
);

/*
 * En una pantalla angosta el índice estorba: ocupa el alto entero antes de que
 * empiece lo que se venía a leer. Se pliega detrás de un botón y se despliega
 * cuando hace falta saltar de lección.
 */
const indiceAbierto = ref(false);

/* ── Marcar como hecha ─────────────────────────────────────────────────────
 * Sólo para lo que no se entrega. Lo demás lo declara la entrega, no un botón:
 * un «ya la hice» sin entregar nada sería mentirse a uno mismo con la barra de
 * progreso, que es justo lo que la vuelve inútil.
 */
const marcando = ref(false);

function completar(seguirALaSiguiente: boolean): void {
    if (props.leccion === null) return;

    const siguiente = props.vecinas.siguiente;

    marcando.value = true;
    router.post(
        `/mis-cursos/${props.curso.id}/aula/${props.leccion.id}/completar`,
        {},
        {
            preserveScroll: !seguirALaSiguiente,
            onSuccess: () => {
                if (seguirALaSiguiente && siguiente) {
                    router.visit(`/mis-cursos/${props.curso.id}/aula/${siguiente.id}`);
                }
            },
            onFinish: () => (marcando.value = false),
        },
    );
}

function descompletar(): void {
    if (props.leccion === null) return;

    marcando.value = true;
    router.delete(`/mis-cursos/${props.curso.id}/aula/${props.leccion.id}/completar`, {
        preserveScroll: true,
        onFinish: () => (marcando.value = false),
    });
}

/* ── Entregar ───────────────────────────────────────────────────────────── */
const entregando = ref(false);

/**
 * Si todavía puede mandar algo.
 *
 * Sin entrega previa, siempre. Con entrega hecha, sólo si la actividad admite
 * reemplazarla: hay trabajos de una sola oportunidad y volver a subir después
 * de leer la retroalimentación sería otra cosa distinta a entregar.
 *
 * Es sólo la cara visible de la regla —el servidor la vuelve a comprobar—, pero
 * ocultar el botón evita que el alumno prepare una reentrega que iba a ser
 * rechazada.
 */
const puedeEntregar = computed(
    () => !props.leccion?.entrega?.entregada_en || props.leccion.permite_reentrega,
);

const formEntrega = useForm<{ contenido: string; archivos: File[] }>({
    contenido: '',
    archivos: [],
});

function abrirEntrega(): void {
    entregando.value = true;
    formEntrega.clearErrors();
    // Reentregar arranca con lo que ya había escrito: casi siempre se corrige,
    // no se empieza de cero.
    formEntrega.contenido = props.leccion?.entrega?.contenido ?? '';
}

function enviarEntrega(): void {
    if (props.leccion === null) return;

    formEntrega.post(`/mis-cursos/actividades/${props.leccion.id}/entrega`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            entregando.value = false;
            formEntrega.reset();
        },
    });
}

/** El estado de la lección, dicho en una línea. */
const estado = computed(() => {
    const l = props.leccion;

    if (l === null) return null;
    if (l.completada && !l.se_entrega) return { texto: 'Completada', color: '#16a34a' };
    if (l.entrega?.calificacion != null) return { texto: 'Calificada', color: '#16a34a' };
    if (l.entrega?.entregada_en) {
        return l.entrega.tarde
            ? { texto: 'Entregada tarde', color: '#d97706' }
            : { texto: 'Entregada · esperando calificación', color: '#2563eb' };
    }
    if (!l.abierta && l.se_entrega) return { texto: 'Cerrada', color: '#dc2626' };

    return { texto: 'Pendiente', color: '#d97706' };
});
</script>

<template>
    <Head :title="leccion ? `${leccion.titulo} · ${curso.materia}` : (curso.materia ?? 'Aula')" />

    <AppLayout titulo="Aula">
        <!-- ── Cabecera: dónde estoy y cuánto llevo ───────────────────── -->
        <section class="tarjeta overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-4 sm:px-6">
                <div class="min-w-0">
                    <Link href="/mis-cursos" class="text-xs text-suave hover:underline">← Mis cursos</Link>
                    <h2 class="mt-1 truncate text-lg font-semibold text-contenido">{{ curso.materia }}</h2>
                    <p class="text-xs text-suave">
                        <span v-if="curso.clave" class="font-mono">{{ curso.clave }}</span>
                        <span v-if="curso.grupo"> · Grupo {{ curso.grupo }}</span>
                        <span v-if="curso.ciclo"> · {{ curso.ciclo }}</span>
                        <span v-if="curso.docente"> · {{ curso.docente }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="`/mis-cursos/${curso.id}`"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                    >
                        Calificaciones y asistencia
                    </Link>
                    <a
                        :href="`/materias/${curso.id}/chat`"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    >
                        Chat
                    </a>
                </div>
            </div>

            <!-- La barra va pegada al borde inferior de la tarjeta: es el pulso
                 del curso, no un dato más entre otros. -->
            <div class="h-1.5 w-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 18%, transparent)' }">
                <div
                    class="h-full transition-all duration-500"
                    :style="{ width: `${progreso.porcentaje}%`, backgroundColor: 'var(--color-acento)' }"
                />
            </div>
        </section>

        <!-- Sin contenido cargado todavía -->
        <section v-if="leccion === null" class="tarjeta px-6 py-16 text-center">
            <svg class="mx-auto h-10 w-10 text-suave" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.libro" />
            </svg>
            <h3 class="mt-4 text-base font-semibold text-contenido">Todavía no hay contenido</h3>
            <p class="mx-auto mt-1 max-w-md text-sm text-suave">
                El docente aún no ha publicado lecciones de esta materia. Cuando lo haga,
                las verás aquí en orden, con tu avance marcado.
            </p>
            <Link
                :href="`/mis-cursos/${curso.id}`"
                class="mt-5 inline-block rounded-lg border px-4 py-2 text-sm font-medium"
                :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
            >
                Ver calificaciones y asistencia
            </Link>
        </section>

        <template v-else>
            <!-- Índice plegado, sólo en pantallas angostas -->
            <button
                type="button"
                class="tarjeta flex w-full items-center gap-3 px-5 py-3 text-left lg:hidden"
                @click="indiceAbierto = !indiceAbierto"
            >
                <svg class="h-5 w-5 shrink-0 text-suave" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.lista" />
                </svg>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-contenido">Contenido del curso</span>
                    <span class="block text-xs text-suave">
                        {{ progreso.completadas }} de {{ progreso.total }} · {{ progreso.porcentaje }}%
                    </span>
                </span>
                <span class="text-xs text-suave">{{ indiceAbierto ? 'Ocultar' : 'Ver' }}</span>
            </button>

            <div class="grid items-start gap-4 lg:grid-cols-[340px_minmax(0,1fr)]">
                <!-- ── Índice ──────────────────────────────────────────── -->
                <aside
                    class="tarjeta overflow-hidden lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)]"
                    :class="indiceAbierto ? '' : 'hidden lg:block'"
                >
                    <IndiceDelCurso
                        :unidades="unidades"
                        :materia-id="curso.id"
                        :activa-id="leccion.id"
                        :progreso="progreso"
                    />
                </aside>

                <!-- ── La lección ──────────────────────────────────────── -->
                <div class="space-y-4">
                    <article class="tarjeta overflow-hidden">
                        <header class="border-b border-borde px-5 py-5 sm:px-8">
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-suave">
                                <span v-if="unidadActual" class="font-medium">{{ unidadActual.nombre }}</span>
                                <span v-if="unidadActual">·</span>
                                <span>Lección {{ leccion.numero }} de {{ progreso.total }}</span>
                            </p>

                            <h1 class="mt-1.5 text-xl font-semibold leading-tight text-contenido sm:text-2xl">
                                {{ leccion.titulo }}
                            </h1>

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" :d="iconoDe[leccion.tipo] ?? ICONOS.documento" />
                                    </svg>
                                    {{ leccion.tipo_etiqueta }}
                                </span>

                                <span
                                    v-if="estado"
                                    class="rounded-full px-2.5 py-1 text-[11px] font-medium"
                                    :style="{ backgroundColor: `color-mix(in srgb, ${estado.color} 13%, transparent)`, color: estado.color }"
                                >
                                    {{ estado.texto }}
                                </span>

                                <!-- Cuánto pesa y a dónde va: es lo que una
                                     plataforma de cursos no tiene que decir y una
                                     escuela sí. -->
                                <span v-if="leccion.componente" class="text-[11px] text-suave">
                                    {{ leccion.componente }} · {{ leccion.puntos }} puntos
                                </span>
                                <span v-else-if="leccion.se_entrega" class="text-[11px] text-suave">
                                    No cuenta para la calificación
                                </span>

                                <CuandoVence
                                    v-if="leccion.se_entrega && !leccion.entrega?.entregada_en"
                                    :dias="leccion.dias"
                                    :fecha="leccion.cierra_en"
                                    :permite-tarde="leccion.permite_tarde"
                                />
                            </div>
                        </header>

                        <!-- El material -->
                        <div v-if="leccion.tiene_contenido" class="prosa px-5 py-6 sm:px-8 sm:py-8" v-html="leccion.contenido" />

                        <!-- Qué hay que hacer con él -->
                        <div
                            v-if="leccion.instrucciones"
                            class="border-t border-borde px-5 py-5 sm:px-8"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 5%, transparent)' }"
                        >
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-suave">
                                {{ leccion.se_entrega ? 'Qué tienes que hacer' : 'Nota del docente' }}
                            </h2>
                            <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-contenido">
                                {{ leccion.instrucciones }}
                            </p>
                        </div>

                        <p
                            v-if="!leccion.tiene_contenido && !leccion.instrucciones"
                            class="px-5 py-10 text-center text-sm text-suave sm:px-8"
                        >
                            Esta lección no trae material cargado.
                        </p>
                    </article>

                    <!-- ── Lo que toca hacer ───────────────────────────── -->

                    <!-- Lectura: la completa el alumno -->
                    <section v-if="!leccion.se_entrega" class="tarjeta px-5 py-5 sm:px-8">
                        <div v-if="leccion.completada" class="flex flex-wrap items-center justify-between gap-3">
                            <p class="flex items-center gap-2 text-sm font-medium" :style="{ color: '#16a34a' }">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.checkCirculo" />
                                </svg>
                                Ya completaste esta lección.
                            </p>
                            <button
                                type="button"
                                class="text-xs text-suave underline"
                                :disabled="marcando"
                                @click="descompletar"
                            >
                                Desmarcar
                            </button>
                        </div>

                        <div v-else class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-suave">
                                Cuando termines de leer, márcala para que se sume a tu avance.
                            </p>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg px-4 py-2 text-sm font-medium transition-opacity disabled:opacity-60"
                                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                    :disabled="marcando"
                                    @click="completar(!!vecinas.siguiente)"
                                >
                                    {{ vecinas.siguiente ? 'Completar y continuar' : 'Marcar como completada' }}
                                </button>
                                <button
                                    v-if="vecinas.siguiente"
                                    type="button"
                                    class="rounded-lg border px-3 py-2 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    :disabled="marcando"
                                    @click="completar(false)"
                                >
                                    Sólo marcar
                                </button>
                            </div>
                        </div>
                    </section>

                    <!-- Examen: su propia pantalla, con su reloj -->
                    <section v-else-if="leccion.tipo === 'examen'" class="tarjeta px-5 py-5 sm:px-8">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-contenido">Examen</h2>
                                <p class="mt-0.5 text-sm text-suave">
                                    <template v-if="leccion.entrega?.calificacion != null">
                                        Obtuviste
                                        <strong class="text-contenido">{{ leccion.entrega.calificacion }}</strong>
                                        de {{ leccion.puntos }} puntos.
                                    </template>
                                    <template v-else-if="leccion.entrega?.entregada_en">
                                        Lo presentaste el {{ leccion.entrega.entregada_en }}.
                                    </template>
                                    <template v-else-if="!leccion.abierta">
                                        Ya cerró. Habla con tu docente si necesitas presentarlo.
                                    </template>
                                    <template v-else>
                                        Se presenta en pantalla aparte, con su tiempo y sus intentos.
                                    </template>
                                </p>
                            </div>
                            <a
                                v-if="leccion.abierta || leccion.entrega?.entregada_en"
                                :href="`/mis-cursos/examenes/${leccion.id}`"
                                class="rounded-lg px-4 py-2 text-sm font-medium"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            >
                                {{ leccion.entrega?.entregada_en ? 'Ver mi examen' : 'Presentar examen' }}
                            </a>
                        </div>
                    </section>

                    <!-- Foro: se participa en el hilo -->
                    <section v-else-if="leccion.tipo === 'foro'" class="tarjeta px-5 py-5 sm:px-8">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-contenido">Foro</h2>
                                <p class="mt-0.5 text-sm text-suave">
                                    {{ leccion.entrega?.entregada_en
                                        ? `Ya participaste el ${leccion.entrega.entregada_en}.`
                                        : 'Se completa participando en el hilo.' }}
                                </p>
                            </div>
                            <a
                                :href="`/materias/${curso.id}/foros/${leccion.id}`"
                                class="rounded-lg px-4 py-2 text-sm font-medium"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            >
                                Entrar al foro
                            </a>
                        </div>
                    </section>

                    <!-- Actividad: se entrega aquí mismo -->
                    <section v-else class="tarjeta overflow-hidden">
                        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-8">
                            <h2 class="text-sm font-semibold text-contenido">Tu entrega</h2>
                            <button
                                v-if="!entregando && leccion.abierta && puedeEntregar"
                                type="button"
                                class="rounded-lg px-4 py-2 text-sm font-medium"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                @click="abrirEntrega"
                            >
                                {{ leccion.entrega?.entregada_en ? 'Volver a entregar' : 'Entregar' }}
                            </button>

                            <!-- Ya entregó y no admite cambios: se explica por
                                 qué no hay botón. Un botón que desaparece sin
                                 decir nada se lee como una falla. -->
                            <span
                                v-else-if="!entregando && leccion.abierta && !puedeEntregar"
                                class="rounded-full px-3 py-1 text-xs font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }"
                            >
                                Entrega única · ya no se puede cambiar
                            </span>
                            <button
                                v-else-if="entregando"
                                type="button"
                                class="rounded-lg border px-3 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="entregando = false"
                            >
                                Cancelar
                            </button>
                            <span v-else class="text-xs" :style="{ color: '#dc2626' }">
                                Cerrada · ya no se aceptan entregas
                            </span>
                        </header>

                        <!-- Lo entregado y lo que le dijeron -->
                        <div
                            v-if="leccion.entrega?.entregada_en && !entregando"
                            class="border-t border-borde px-5 py-4 sm:px-8"
                        >
                            <p class="text-xs text-suave">
                                Entregaste el {{ leccion.entrega.entregada_en }}
                                <span v-if="leccion.entrega.tarde" :style="{ color: '#d97706' }"> · marcada como tarde</span>
                            </p>

                            <p v-if="leccion.entrega.contenido" class="mt-2 whitespace-pre-line text-sm text-contenido">
                                {{ leccion.entrega.contenido }}
                            </p>

                            <ul v-if="leccion.entrega.archivos.length" class="mt-3 flex flex-wrap gap-2">
                                <li v-for="f in leccion.entrega.archivos" :key="f.id">
                                    <a
                                        :href="`/mis-cursos/entregas/archivos/${f.id}`"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs"
                                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                    >
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.documento" />
                                        </svg>
                                        {{ f.nombre }}
                                    </a>
                                </li>
                            </ul>

                            <div
                                v-if="leccion.entrega.calificacion != null"
                                class="mt-4 rounded-lg px-4 py-3"
                                :style="{ backgroundColor: 'color-mix(in srgb, #16a34a 9%, transparent)' }"
                            >
                                <p class="text-sm">
                                    <span class="font-semibold" :style="{ color: '#16a34a' }">
                                        {{ leccion.entrega.calificacion }} / {{ leccion.puntos }}
                                    </span>
                                    <span class="text-suave"> · calificada</span>
                                </p>
                                <p v-if="leccion.entrega.retroalimentacion" class="mt-2 whitespace-pre-line text-sm text-contenido">
                                    {{ leccion.entrega.retroalimentacion }}
                                </p>
                            </div>
                        </div>

                        <p
                            v-else-if="!entregando"
                            class="border-t border-borde px-5 py-6 text-sm text-suave sm:px-8"
                        >
                            Todavía no has entregado nada.
                        </p>

                        <!-- Formulario -->
                        <form
                            v-if="entregando"
                            class="space-y-4 border-t border-borde px-5 py-5 sm:px-8"
                            @submit.prevent="enviarEntrega"
                        >
                            <div>
                                <label class="mb-1 block text-sm font-medium">Tu respuesta</label>
                                <textarea
                                    v-model="formEntrega.contenido"
                                    rows="6"
                                    class="w-full rounded-lg border px-3 py-2 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    placeholder="Escribe aquí, o adjunta un archivo abajo."
                                />
                                <p v-if="formEntrega.errors.contenido" class="mt-1 text-xs text-red-600">
                                    {{ formEntrega.errors.contenido }}
                                </p>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium">Archivos</label>
                                <ZonaArchivos v-model="formEntrega.archivos" :max="5" :max-mb="20" />
                                <p v-if="formEntrega.errors.archivos" class="mt-1 text-xs text-red-600">
                                    {{ formEntrega.errors.archivos }}
                                </p>
                            </div>

                            <!-- Una sola oportunidad: se dice ANTES de mandar,
                                 que es cuando todavía se puede revisar el
                                 archivo. Decirlo después no sirve de nada. -->
                            <p
                                v-if="!leccion.permite_reentrega"
                                class="rounded-lg px-3 py-2 text-xs"
                                :style="{ backgroundColor: 'color-mix(in srgb, #d97706 10%, transparent)', color: '#b45309' }"
                            >
                                <strong>Esta actividad admite una sola entrega.</strong>
                                Revisa bien lo que vas a mandar: después no podrás cambiarlo.
                            </p>

                            <p v-else-if="leccion.entrega?.entregada_en" class="text-xs" :style="{ color: '#d97706' }">
                                Volver a entregar reemplaza lo anterior y quita la calificación:
                                se califica lo que quede.
                            </p>

                            <BotonPrincipal :procesando="formEntrega.processing" texto="Entregar" icono="crear" />
                        </form>
                    </section>

                    <!-- ── Avanzar ─────────────────────────────────────── -->
                    <nav class="flex items-stretch gap-3">
                        <Link
                            v-if="vecinas.anterior"
                            :href="`/mis-cursos/${curso.id}/aula/${vecinas.anterior.id}`"
                            class="tarjeta flex min-w-0 flex-1 items-center gap-3 px-4 py-3 transition-colors hover:border-[var(--color-acento)]"
                        >
                            <svg class="h-4 w-4 shrink-0 text-suave" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.flechaIzquierda" />
                            </svg>
                            <span class="min-w-0">
                                <span class="block text-[11px] text-suave">Anterior</span>
                                <span class="block truncate text-sm text-contenido">{{ vecinas.anterior.titulo }}</span>
                            </span>
                        </Link>
                        <span v-else class="hidden flex-1 sm:block" />

                        <Link
                            v-if="vecinas.siguiente"
                            :href="`/mis-cursos/${curso.id}/aula/${vecinas.siguiente.id}`"
                            class="tarjeta flex min-w-0 flex-1 items-center justify-end gap-3 px-4 py-3 text-right transition-colors hover:border-[var(--color-acento)]"
                        >
                            <span class="min-w-0">
                                <span class="block text-[11px] text-suave">Siguiente</span>
                                <span class="block truncate text-sm font-medium text-contenido">{{ vecinas.siguiente.titulo }}</span>
                            </span>
                            <svg class="h-4 w-4 shrink-0" :style="{ color: 'var(--color-acento)' }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.flechaDerecha" />
                            </svg>
                        </Link>

                        <!-- Fin del curso: se dice, no se deja en silencio -->
                        <span
                            v-else
                            class="tarjeta flex min-w-0 flex-1 items-center justify-end gap-2 px-4 py-3 text-right text-sm text-suave"
                        >
                            {{ progreso.completadas === progreso.total
                                ? '¡Terminaste el curso!'
                                : 'Última lección del curso' }}
                        </span>
                    </nav>
                </div>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
/*
 * El material lo escribió un docente en un editor: aquí se le da la tipografía
 * de un texto para leer, no la de una ficha de datos. Renglón alto, jerarquía
 * clara de títulos y ancho contenido —una línea de 120 caracteres se lee mal
 * por más grande que sea la pantalla—.
 */
/*
 * El texto se lee en medida corta (68ch) pero la tarjeta es más ancha, y una
 * FIGURA no tiene por qué encogerse a la medida del texto: un diagrama a 493 px
 * dentro de una columna de lectura se ve apretado cuando al lado sobra espacio.
 *
 * Por eso el ancho de lectura NO va en `.prosa` sino en cada bloque de texto:
 * así las imágenes, los iframes y las tablas quedan libres para usar el ancho
 * completo, y el párrafo sigue leyéndose en su línea corta.
 */
.prosa {
    color: var(--color-contenido);
    font-size: 0.95rem;
    line-height: 1.75;
}

.prosa :deep(p),
.prosa :deep(ul),
.prosa :deep(ol),
.prosa :deep(blockquote),
.prosa :deep(h1),
.prosa :deep(h2),
.prosa :deep(h3),
.prosa :deep(h4) {
    max-width: 68ch;
}

.prosa :deep(p) {
    margin-bottom: 1em;
}

.prosa :deep(p:last-child) {
    margin-bottom: 0;
}

.prosa :deep(h1),
.prosa :deep(h2),
.prosa :deep(h3),
.prosa :deep(h4) {
    font-weight: 600;
    line-height: 1.3;
    margin-top: 1.6em;
    margin-bottom: 0.5em;
}

.prosa :deep(h1) { font-size: 1.5rem; }
.prosa :deep(h2) { font-size: 1.25rem; }
.prosa :deep(h3) { font-size: 1.1rem; }

.prosa :deep(h1:first-child),
.prosa :deep(h2:first-child),
.prosa :deep(h3:first-child) {
    margin-top: 0;
}

.prosa :deep(ul),
.prosa :deep(ol) {
    margin: 0 0 1em 1.4em;
}

.prosa :deep(ul) { list-style: disc; }
.prosa :deep(ol) { list-style: decimal; }
.prosa :deep(li) { margin-bottom: 0.35em; }

.prosa :deep(blockquote) {
    border-left: 3px solid var(--color-acento);
    padding-left: 1rem;
    margin: 0 0 1em;
    color: var(--color-suave);
}

.prosa :deep(a) {
    color: var(--color-acento);
    text-decoration: underline;
}

.prosa :deep(hr) {
    border: 0;
    border-top: 1px solid var(--color-borde);
    margin: 1.75em 0;
}

.prosa :deep(code) {
    font-family: ui-monospace, monospace;
    font-size: 0.875em;
    background: color-mix(in srgb, var(--color-suave) 12%, transparent);
    border-radius: 4px;
    padding: 0.1em 0.35em;
}

.prosa :deep(pre) {
    background: color-mix(in srgb, var(--color-suave) 12%, transparent);
    border-radius: 8px;
    padding: 0.9rem 1rem;
    overflow-x: auto;
    margin-bottom: 1em;
}

/*
 * La imagen se pinta a su tamaño natural, sin pasar del ancho de la tarjeta ni
 * estirarse más allá de lo que mide —una figura de 400 px ampliada a 800 se ve
 * borrosa—. El `width`/`height` del `<img>` reserva el hueco con su proporción
 * y evita el salto de la página al cargar.
 */
.prosa :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    margin: 0.5em 0 1em;
}

/*
 * Lo incrustado (video, SCORM) ocupa el ancho hasta un tope: sin él, en una
 * tarjeta ancha un video se estira a lo bestia. El alto lo pone el propio
 * bloque (el atributo `height` del iframe), no se fuerza una proporción: un
 * SCORM puede necesitar un marco más alto que un video.
 */
.prosa :deep(iframe) {
    width: 100%;
    max-width: 820px;
    border: 0;
    border-radius: 10px;
    margin: 0.5em 0 1em;
    display: block;
}

.prosa :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1em;
    font-size: 0.9em;
}

.prosa :deep(th),
.prosa :deep(td) {
    border: 1px solid var(--color-borde);
    padding: 0.5rem 0.65rem;
    text-align: left;
}

.prosa :deep(th) {
    background: color-mix(in srgb, var(--color-suave) 8%, transparent);
    font-weight: 600;
}
</style>
