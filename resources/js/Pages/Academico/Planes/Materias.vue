<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import FormularioAsignatura from '@/Components/FormularioAsignatura.vue';
import CargaHoraria from '@/Components/CargaHoraria.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

interface Materia {
    id: number;
    asignatura_id: number;
    asignatura: string | null;
    asignatura_clave: string | null;
    clave_en_plan: string;
    periodo: number | null;
    tipo: string;
    creditos: number | null;
    area: string | null;
    area_color: string | null;
}

const props = defineProps<{
    plan: {
        id: number;
        nombre: string;
        clave: string;
        carrera: string | null;
        total_periodos: number | null;
        total_creditos: number | null;
        minimo_creditos: number;
        periodo_unidad: string;
    };
    materias: Materia[];
    creditosCargados: number;
    tiposAsignatura: { id: number; nombre: string }[];
    clasificaciones: { id: number; nombre: string }[];
    areas: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const mostrarAlta = ref(false);

// Carga de asignaturas por Excel.
const page = usePage();
const erroresCarga = computed(() => ((page.props as any).flash?.erroresCarga ?? []) as { hoja: string; fila: number; mensaje: string }[]);
const mostrarCarga = ref(false);
const carga = useForm<{ archivo: File | null }>({ archivo: null });

function subirAsignaturas(archivo: File | null): void {
    if (!archivo) {
        return;
    }
    carga.archivo = archivo;
    carga.post(`/academico/planes/${props.plan.id}/asignaturas/importar`, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => carga.reset(),
    });
}

// Vista de la malla: «lista» (tabla por periodo) o «cuadrícula» (tarjetas por
// nivel, coloreadas por área). Se recuerda la preferida entre visitas.
const vista = ref<'lista' | 'cuadricula'>(
    (localStorage.getItem('malla-vista') as 'lista' | 'cuadricula') || 'lista',
);
watch(vista, (v) => localStorage.setItem('malla-vista', v));

// Si se llega desde «Nueva asignatura» del catálogo (…/materias?nueva=1), se
// abre el alta directo.
onMounted(() => {
    if (props.puedeEditar && new URLSearchParams(window.location.search).get('nueva') === '1') {
        abrirAlta();
    }
});

// Leyenda de áreas presentes en la malla (nombre → color), para leer la
// cuadrícula sin adivinar qué academia es cada color.
const leyendaAreas = computed(() => {
    const mapa = new Map<string, string | null>();
    for (const m of props.materias) {
        if (m.area && !mapa.has(m.area)) {
            mapa.set(m.area, m.area_color);
        }
    }
    return [...mapa].map(([nombre, color]) => ({ nombre, color }));
});

// La asignatura se CREA aquí (ya no se elige una del catálogo). El form lleva sus
// datos + la ubicación en el plan (periodo y tipo: obligatoria/optativa…).
const form = useForm({
    // Asignatura nueva
    identificador: '',
    clave: '',
    nombre: '',
    creditos: null as number | null,
    tipo_asignatura_id: null as number | null,
    clasificacion_id: null as number | null,
    area_id: null as number | null,
    horas_teoria: null as number | null,
    horas_practica: null as number | null,
    horas_acompanamiento: null as number | null,
    horas_independientes: null as number | null,
    // Ubicación en el plan
    periodo: null as number | null,
    tipo: 'obligatoria',
});

// El periodo se elige de una lista de 1 al total de periodos del plan: no tiene
// sentido teclear un número fuera de la malla. Vacío = sin periodo fijo (optativas).
const opcionesPeriodo = computed(() =>
    Array.from({ length: props.plan.total_periodos ?? 0 }, (_, i) => ({ valor: i + 1, texto: `${props.plan.periodo_unidad} ${i + 1}` })),
);

interface GrupoMalla {
    clave: string;
    titulo: string;
    /** Periodo destino para el botón «+» y el arrastre; null = sin periodo/optativas. */
    periodo: number | null;
    optativa: boolean;
    lista: Materia[];
}

/**
 * Malla agrupada. Se muestran SIEMPRE los N periodos que declara el plan (aunque
 * estén vacíos), para poder cargar en cualquiera desde el inicio. Las materias
 * sin periodo (o fuera de rango) caen en un bloque «sin periodo», y TODAS las
 * optativas en el suyo al final (no pertenecen a un periodo fijo).
 *
 * Es un `ref` (no computed) porque `vuedraggable` muta las listas al arrastrar;
 * se reconstruye cuando cambian las materias del servidor.
 */
const grupos = ref<GrupoMalla[]>([]);

function construirGrupos(): void {
    const porPeriodo = new Map<number, Materia[]>();
    const sinPeriodo: Materia[] = [];
    const optativas: Materia[] = [];
    const total = props.plan.total_periodos ?? 0;

    for (const materia of props.materias) {
        if (materia.tipo === 'optativa') {
            optativas.push(materia);
            continue;
        }
        if (materia.periodo !== null && materia.periodo >= 1 && materia.periodo <= total) {
            porPeriodo.set(materia.periodo, [...(porPeriodo.get(materia.periodo) ?? []), materia]);
        } else {
            sinPeriodo.push(materia);
        }
    }

    const nuevos: GrupoMalla[] = [];

    for (let p = 1; p <= total; p++) {
        nuevos.push({
            clave: `periodo-${p}`,
            titulo: `${props.plan.periodo_unidad} ${p}`,
            periodo: p,
            optativa: false,
            lista: porPeriodo.get(p) ?? [],
        });
    }

    if (sinPeriodo.length) {
        nuevos.push({
            clave: 'sin-periodo',
            titulo: `Sin ${props.plan.periodo_unidad.toLowerCase()} asignado`,
            periodo: null,
            optativa: false,
            lista: sinPeriodo,
        });
    }

    if (optativas.length) {
        nuevos.push({ clave: 'optativas', titulo: 'Optativas', periodo: null, optativa: true, lista: optativas });
    }

    grupos.value = nuevos;
}

watch(() => props.materias, construirGrupos, { immediate: true, deep: true });

// Al soltar una materia en otro periodo (evento `added` de vuedraggable), se
// persiste su nuevo periodo; el tipo no cambia. Optativas quedan fuera del
// arrastre. Al confirmar el servidor recarga la malla y se reconstruyen grupos.
function alMover(grupo: GrupoMalla, evento: any): void {
    const movida = evento?.added?.element as Materia | undefined;

    if (!movida || grupo.optativa) {
        return;
    }

    router.put(`/academico/planes/${props.plan.id}/materias/${movida.id}`, {
        periodo: grupo.periodo,
        tipo: movida.tipo,
    }, { preserveScroll: true });
}

const opcionesTipo = [
    { valor: 'obligatoria', texto: 'Obligatoria' },
    { valor: 'optativa', texto: 'Optativa' },
    { valor: 'tronco_comun', texto: 'Tronco común' },
];

/** Diferencia entre lo cargado y lo que el plan declara: ayuda a cuadrar la malla. */
const diferenciaCreditos = computed(() =>
    props.plan.total_creditos == null ? 0 : props.creditosCargados - props.plan.total_creditos,
);

function abrirAlta(): void {
    mostrarAlta.value = true;
    form.reset();
    form.clearErrors();
}

// «+» de un periodo: abre el alta ya apuntando a ese periodo (y como optativa si
// se pulsó en el bloque de optativas). Sube al formulario para capturar.
function abrirAltaEn(grupo: GrupoMalla): void {
    abrirAlta();
    form.periodo = grupo.periodo;
    form.tipo = grupo.optativa ? 'optativa' : 'obligatoria';
    nextTick(() => document.getElementById('alta-materia')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}

// La ubicación de una materia ya existente se edita en la ficha (hub), no aquí:
// este formulario sólo da de alta asignaturas nuevas en el plan.
function guardar(): void {
    form.post(`/academico/planes/${props.plan.id}/materias`, {
        preserveScroll: true,
        onSuccess: () => {
            mostrarAlta.value = false;
            form.reset();
        },
    });
}

function quitar(materia: Materia): void {
    if (!confirm(`¿Quitar "${materia.asignatura}" del plan?`)) {
        return;
    }

    router.delete(`/academico/planes/${props.plan.id}/materias/${materia.id}`, { preserveScroll: true });
}

const etiquetaTipo = (tipo: string) => opcionesTipo.find((o) => o.valor === tipo)?.texto ?? tipo;

// El color del área pinta la tarjeta; el texto se elige oscuro o claro según la
// luminancia para que se lea sobre cualquier color (el usuario puede editarlo).
function fondoArea(color: string | null): string {
    return color || '#EAE8E6';
}
function textoSobre(color: string | null): string {
    const hex = (color || '#EAE8E6').replace('#', '');
    const r = parseInt(hex.slice(0, 2), 16);
    const g = parseInt(hex.slice(2, 4), 16);
    const b = parseInt(hex.slice(4, 6), 16);
    const luminancia = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminancia > 0.6 ? '#1e293b' : '#ffffff';
}
</script>

<template>
    <Head :title="`Materias · ${plan.nombre}`" />

    <AppLayout titulo="Malla curricular">
        <NavAcademico />

        <!-- Encabezado del plan -->
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">{{ plan.nombre }}</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ plan.carrera }} · <span class="font-mono text-xs">{{ plan.clave }}</span>
                    </p>
                </div>
                <a href="/academico/planes" class="text-sm text-indigo-600 hover:text-indigo-700">
                    ← Volver a planes
                </a>
            </div>

            <dl class="mt-5 grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Materias</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-800">{{ materias.length }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Créditos cargados</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-800">{{ creditosCargados }}</dd>
                </div>
                <div v-if="plan.total_creditos != null">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Declarados en el plan</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-800">{{ plan.total_creditos }}</dd>
                </div>
                <div v-if="plan.total_creditos != null">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Diferencia</dt>
                    <dd
                        class="mt-0.5 text-lg font-semibold"
                        :class="diferenciaCreditos === 0 ? 'text-emerald-600' : 'text-amber-600'"
                    >
                        {{ diferenciaCreditos > 0 ? '+' : '' }}{{ diferenciaCreditos }}
                    </dd>
                </div>
            </dl>

            <p v-if="plan.total_creditos != null && diferenciaCreditos !== 0 && materias.length" class="mt-3 text-xs text-amber-600">
                Los créditos cargados no cuadran con los declarados en el plan. Revisa la malla o ajusta el
                total del plan.
            </p>
        </section>

        <!-- Alta -->
        <section id="alta-materia" v-if="puedeEditar" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">Agregar materia</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        La asignatura se crea aquí mismo y queda ligada a este plan. Para editar una ya
                        existente usa el botón «Editar» de la malla.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        @click="mostrarCarga = !mostrarCarga"
                    >
                        {{ mostrarCarga ? 'Ocultar' : 'Cargar desde Excel' }}
                    </button>
                    <BotonAccion v-if="!mostrarAlta" variante="nuevo" texto="Agregar materia" @click="abrirAlta" />
                </div>
            </div>

            <!-- Carga de asignaturas por Excel -->
            <div v-if="mostrarCarga" class="mt-5 space-y-4 border-t border-slate-100 pt-5">
                <a
                    :href="`/academico/planes/${plan.id}/plantilla-asignaturas`"
                    class="inline-flex items-center gap-2 text-sm font-medium"
                    :style="{ color: 'var(--color-acento)' }"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M12 3v13.5m0 0 4.5-4.5M12 16.5 7.5 12" /></svg>
                    Descargar plantilla de asignaturas (.xlsx)
                </a>

                <ZonaArchivo
                    accept=".xlsx"
                    texto="Arrastra la plantilla llena (.xlsx) o haz clic para seleccionarla"
                    ayuda="Las asignaturas se agregan a este plan; se valida antes de crear nada."
                    :cargado="null"
                    :ocupado="carga.processing"
                    @archivo="subirAsignaturas"
                />

                <div
                    v-if="erroresCarga.length"
                    class="rounded-lg border p-3 text-sm"
                    :style="{ borderColor: '#f59e0b', backgroundColor: 'color-mix(in srgb, #f59e0b 8%, transparent)' }"
                >
                    <p class="font-medium">El archivo tiene {{ erroresCarga.length }} error(es); corrígelos y vuelve a subirlo:</p>
                    <ul class="mt-2 max-h-64 space-y-1 overflow-auto text-xs">
                        <li v-for="(e, i) in erroresCarga" :key="i">
                            <span class="font-medium">{{ e.hoja }} · fila {{ e.fila }}:</span> {{ e.mensaje }}
                        </li>
                    </ul>
                </div>
            </div>

            <form v-if="mostrarAlta" class="mt-5 space-y-4" @submit.prevent="guardar">
                <!-- Datos de la asignatura: mismo componente que la ficha del plan. -->
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        Datos de la asignatura
                    </p>
                    <FormularioAsignatura
                        :form="form"
                        :tipos-asignatura="tiposAsignatura"
                        :clasificaciones="clasificaciones"
                        :areas="areas"
                    />
                </div>

                <div class="border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Carga horaria</p>
                    <CargaHoraria :form="form" />
                </div>

                <!-- Ubicación en el plan. -->
                <div class="border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Ubicación en el plan</p>
                    <div class="grid gap-4 sm:grid-cols-4">
                        <CampoSelect
                            v-model="form.periodo"
                            etiqueta="Periodo"
                            :opciones="opcionesPeriodo"
                            vacio="Sin periodo fijo (optativas)"
                            :error="form.errors.periodo"
                        />
                        <CampoSelect
                            v-model="form.tipo"
                            etiqueta="Tipo en el plan"
                            requerido
                            :opciones="opcionesTipo"
                            :error="form.errors.tipo"
                        />
                    </div>
                </div>

                <div class="flex items-end gap-2">
                    <BotonPrincipal :procesando="form.processing" texto="Agregar" icono="crear" />
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                        @click="mostrarAlta = false; form.reset();"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <!-- Malla agrupada (periodos + un bloque de Optativas) -->
        <template v-if="grupos.length">
            <!-- Barra: conmutador de vista + leyenda de áreas por color. -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex rounded-lg border p-0.5" :style="{ borderColor: 'var(--color-borde)' }">
                    <button
                        v-for="opcion in [{ v: 'lista', t: 'Lista' }, { v: 'cuadricula', t: 'Cuadrícula' }]"
                        :key="opcion.v"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                        :style="vista === opcion.v
                            ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                            : { color: 'var(--color-suave)' }"
                        @click="vista = opcion.v as 'lista' | 'cuadricula'"
                    >
                        {{ opcion.t }}
                    </button>
                </div>

                <div v-if="vista === 'cuadricula' && leyendaAreas.length" class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                    <span
                        v-for="area in leyendaAreas"
                        :key="area.nombre"
                        class="inline-flex items-center gap-1.5 text-xs"
                        :style="{ color: 'var(--color-suave)' }"
                    >
                        <span class="h-3 w-3 rounded-sm border" :style="{ backgroundColor: fondoArea(area.color), borderColor: 'var(--color-borde)' }" />
                        {{ area.nombre }}
                    </span>
                </div>
            </div>

            <!-- VISTA CUADRÍCULA: una columna por nivel; cada materia es una
                 tarjeta con el color de su área. Toda la tarjeta abre la ficha. -->
            <section v-if="vista === 'cuadricula'" class="overflow-x-auto pb-2">
                <div class="flex gap-4" :style="{ minWidth: 'min-content' }">
                    <div v-for="grupo in grupos" :key="grupo.clave" class="w-52 shrink-0 sm:w-60">
                        <div
                            class="mb-3 flex items-center justify-between gap-2 rounded-lg px-3 py-2"
                            :style="grupo.optativa
                                ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }
                                : { backgroundColor: 'var(--color-suave-fondo, color-mix(in srgb, var(--color-acento) 5%, transparent))', color: 'var(--color-contenido)' }"
                        >
                            <span class="flex items-center gap-1.5 text-sm font-semibold">
                                <button
                                    v-if="puedeEditar"
                                    type="button"
                                    class="grid h-5 w-5 place-items-center rounded-md text-base leading-none transition hover:brightness-110"
                                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                    :title="`Agregar en ${grupo.titulo}`"
                                    @click="abrirAltaEn(grupo)"
                                >
                                    +
                                </button>
                                <svg v-if="grupo.optativa" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                                {{ grupo.titulo }}
                            </span>
                            <span class="text-xs opacity-70">{{ grupo.lista.length }}</span>
                        </div>

                        <draggable
                            :list="grupo.lista"
                            :group="grupo.optativa ? { name: 'malla-opt', pull: false, put: false } : 'malla-materias'"
                            :disabled="!puedeEditar"
                            item-key="id"
                            :animation="150"
                            handle=".asa-materia"
                            ghost-class="fantasma-arrastre"
                            class="min-h-[2.5rem] space-y-3"
                            @change="(e) => alMover(grupo, e)"
                        >
                            <template #item="{ element: materia }">
                                <a
                                    :href="`/academico/planes/${plan.id}/materias/${materia.id}`"
                                    class="block rounded-xl border p-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                                    :style="{ backgroundColor: fondoArea(materia.area_color), borderColor: 'color-mix(in srgb, #000 8%, transparent)', color: textoSobre(materia.area_color) }"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="flex items-center gap-1">
                                            <span
                                                v-if="puedeEditar"
                                                class="asa-materia cursor-grab select-none text-sm leading-none opacity-70"
                                                title="Arrastrar a otro periodo"
                                                @click.prevent
                                            >⠿</span>
                                            <span class="font-mono text-[11px] opacity-80">{{ materia.clave_en_plan }}</span>
                                        </span>
                                        <span class="shrink-0 text-[11px] font-semibold opacity-90">
                                            {{ materia.creditos ?? '—' }} cr
                                        </span>
                                    </div>
                                    <p class="mt-1.5 text-sm font-semibold leading-snug">{{ materia.asignatura }}</p>
                                    <div class="mt-2 flex items-center justify-between gap-2">
                                        <span class="truncate text-[11px] opacity-80">{{ materia.area || 'Sin área' }}</span>
                                        <span
                                            v-if="materia.tipo === 'optativa'"
                                            class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                            :style="{ backgroundColor: 'color-mix(in srgb, #000 12%, transparent)' }"
                                        >
                                            Optativa
                                        </span>
                                    </div>
                                </a>
                            </template>
                        </draggable>

                        <p v-if="!grupo.lista.length" class="mt-1 rounded-lg border border-dashed p-3 text-center text-xs" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            Sin materias
                        </p>
                    </div>
                </div>
            </section>

            <!-- VISTA LISTA: la tabla por periodo de siempre. -->
            <section v-else class="space-y-4">
            <div
                v-for="grupo in grupos"
                :key="grupo.clave"
                class="tarjeta overflow-hidden"
            >
                <div
                    class="flex items-center justify-between border-b px-6 py-3"
                    :style="grupo.optativa
                        ? { borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 6%, transparent)' }
                        : { borderColor: 'var(--color-borde)' }"
                >
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <button
                            v-if="puedeEditar"
                            type="button"
                            class="grid h-5 w-5 place-items-center rounded-md text-base leading-none transition hover:brightness-110"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            :title="`Agregar en ${grupo.titulo}`"
                            @click="abrirAltaEn(grupo)"
                        >
                            +
                        </button>
                        <svg v-if="grupo.optativa" class="h-4 w-4" :style="{ color: 'var(--color-acento)' }" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                        {{ grupo.titulo }}
                    </h3>
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ grupo.lista.length }} materia(s) ·
                        {{ grupo.lista.reduce((suma, m) => suma + (m.creditos ?? 0), 0) }} créditos
                    </span>
                </div>

                <table class="w-full text-sm">
                    <draggable
                        tag="tbody"
                        :list="grupo.lista"
                        :group="grupo.optativa ? { name: 'malla-opt', pull: false, put: false } : 'malla-materias'"
                        :disabled="!puedeEditar"
                        item-key="id"
                        :animation="150"
                        handle=".asa-fila"
                        ghost-class="fantasma-arrastre"
                        @change="(e) => alMover(grupo, e)"
                    >
                        <template #item="{ element: materia }">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                    <span class="flex items-center gap-1.5">
                                        <span
                                            v-if="puedeEditar"
                                            class="asa-fila cursor-grab select-none text-sm opacity-60"
                                            title="Arrastrar a otro periodo"
                                            @click.stop
                                        >⠿</span>
                                        {{ materia.clave_en_plan }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium">{{ materia.asignatura }}</span>
                                    <span class="block font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                        catálogo: {{ materia.asignatura_clave }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-1 text-xs"
                                        :class="{
                                            'bg-sky-100 text-sky-700': materia.tipo === 'tronco_comun',
                                            'bg-indigo-50 text-indigo-700': materia.tipo === 'obligatoria',
                                        }"
                                        :style="materia.tipo === 'optativa' ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' } : {}"
                                    >
                                        {{ etiquetaTipo(materia.tipo) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3" :style="{ color: 'var(--color-suave)' }">
                                    {{ materia.creditos }} cr.
                                </td>
                                <td class="px-6 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <!-- «Editar» abre la ficha completa: datos, descriptores, imágenes,
                                             requisitos y evaluación en un solo lugar. -->
                                        <BotonAccion
                                            v-if="puedeEditar"
                                            variante="editar"
                                            :href="`/academico/planes/${plan.id}/materias/${materia.id}`"
                                        />
                                        <BotonAccion
                                            v-else
                                            variante="ver"
                                            texto="Ver"
                                            :href="`/academico/planes/${plan.id}/materias/${materia.id}`"
                                        />
                                        <BotonAccion v-if="puedeEditar" variante="eliminar" @click="quitar(materia)" />
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </draggable>
                    <tbody v-if="!grupo.lista.length">
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-xs" :style="{ color: 'var(--color-suave)' }">
                                Sin materias en este {{ plan.periodo_unidad.toLowerCase() }}.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </section>
        </template>

        <p v-else class="rounded-xl bg-white px-4 py-12 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
            Este plan no declara periodos todavía. Define el total de periodos del plan para armar la malla.
        </p>
    </AppLayout>
</template>

<style scoped>
/* La materia que se arrastra se ve translúcida en su hueco original. */
.fantasma-arrastre {
    opacity: 0.4;
}
</style>
