<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import FormularioAsignatura from '@/Components/FormularioAsignatura.vue';
import CargaHoraria from '@/Components/CargaHoraria.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface Materia {
    id: number;
    asignatura_id: number;
    asignatura: string | null;
    asignatura_clave: string | null;
    clave_en_plan: string;
    periodo: number | null;
    /** Nombre del tipo de la ASIGNATURA (OBLIGATORIA, OPTATIVA…), del catálogo. */
    tipo: string | null;
    creditos: number | null;
    area: string | null;
    area_color: string | null;
}

const props = defineProps<{
    plan: {
        id: number;
        nombre: string;
        clave: string;
        programa_academico: string | null;
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

/**
 * De cuál de las dos cargas son los errores que llegaron.
 *
 * `flash.erroresCarga` es uno solo para la pantalla y lo pintaban las DOS
 * secciones —asignaturas e historial—: subir un historial con errores los mostraba
 * también bajo «Agregar materia», señalando un archivo que ahí nadie tocó.
 */
const ultimaCarga = ref<'asignaturas' | 'historial' | null>(null);

/** Excel y captura manual dan de alta lo mismo: abrir una cierra la otra. */
function alternarCarga(): void {
    mostrarCarga.value = ! mostrarCarga.value;

    if (mostrarCarga.value) {
        mostrarAlta.value = false;
    }
}

function subirAsignaturas(archivo: File | null): void {
    if (!archivo) {
        return;
    }
    ultimaCarga.value = 'asignaturas';
    carga.archivo = archivo;
    carga.post(`/academico/planes/${props.plan.id}/asignaturas/importar`, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => carga.reset(),
    });
}

// Carga de historial académico (calificaciones) de este plan por Excel.
const mostrarHistorial = ref(false);
const cargaHistorial = useForm<{ archivo: File | null }>({ archivo: null });

function subirHistorial(archivo: File | null): void {
    if (!archivo) {
        return;
    }
    ultimaCarga.value = 'historial';
    cargaHistorial.archivo = archivo;
    cargaHistorial.post(`/academico/planes/${props.plan.id}/historial/importar`, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => cargaHistorial.reset(),
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

/**
 * ¿Este tipo del catálogo es el de optativa? Se compara por nombre y sin
 * distinguir mayúsculas porque el catálogo se siembra en altas («OPTATIVA») pero
 * la escuela puede reescribirlo desde Configuración.
 */
function esOptativa(tipo: string | null | undefined): boolean {
    return (tipo ?? '').trim().toUpperCase() === 'OPTATIVA';
}

// La asignatura se CREA aquí (ya no se elige una del catálogo). El form lleva sus
// datos + su ubicación en el plan (el periodo). Que sea obligatoria u optativa lo
// dice `tipo_asignatura_id`, el tipo del catálogo, no un campo aparte del plan.
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
});

/** Id del tipo «OPTATIVA» del catálogo, para el «+» del bloque de optativas. */
const idTipoOptativa = computed(
    () => props.tiposAsignatura.find((t) => esOptativa(t.nombre))?.id ?? null,
);

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
        if (esOptativa(materia.tipo)) {
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
// persiste su nuevo periodo. Optativas quedan fuera del arrastre. Al confirmar el
// servidor recarga la malla y se reconstruyen grupos.
function alMover(grupo: GrupoMalla, evento: any): void {
    const movida = evento?.added?.element as Materia | undefined;

    if (!movida || grupo.optativa) {
        return;
    }

    router.put(`/academico/planes/${props.plan.id}/materias/${movida.id}`, {
        periodo: grupo.periodo,
    }, { preserveScroll: true });
}

/** Diferencia entre lo cargado y lo que el plan declara: ayuda a cuadrar la malla. */
const diferenciaCreditos = computed(() =>
    props.plan.total_creditos == null ? 0 : props.creditosCargados - props.plan.total_creditos,
);

function abrirAlta(): void {
    mostrarAlta.value = true;
    mostrarCarga.value = false;
    form.reset();
    form.clearErrors();
}

/**
 * Qué tiene ya el periodo elegido.
 *
 * Se pone al lado del selector porque es lo que uno comprueba justo antes de
 * agregar otra materia —si ese semestre va lleno, si es el que falta— y hasta
 * ahora había que subir a la malla a contarlo a ojo.
 */
const resumenDelPeriodo = computed(() => {
    if (form.periodo === null || form.periodo === '') {
        return 'Las optativas no se fijan a un periodo.';
    }

    const materias = props.materias.filter((m) => m.periodo === form.periodo);

    if (materias.length === 0) {
        return 'Este periodo todavía no tiene materias.';
    }

    const creditos = materias.reduce((suma, m) => suma + (m.creditos ?? 0), 0);

    return `Ya tiene ${materias.length} materia(s) y ${creditos} crédito(s).`;
});

// «+» de un periodo: abre el alta ya apuntando a ese periodo. Si se pulsó en el
// bloque de optativas, preselecciona ese tipo de asignatura (el del catálogo).
// Sube al formulario para capturar.
function abrirAltaEn(grupo: GrupoMalla): void {
    abrirAlta();
    form.periodo = grupo.periodo;

    if (grupo.optativa && idTipoOptativa.value !== null) {
        form.tipo_asignatura_id = idTipoOptativa.value;
    }

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
        <PestanasSeccion />

        <!-- Encabezado del plan -->
        <section class="tarjeta p-6">
            <BotonVolver href="/academico/planes" texto="Planes" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-contenido">{{ plan.nombre }}</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        {{ plan.programaAcademico }} · <span class="font-mono text-xs">{{ plan.clave }}</span>
                    </p>
                </div>
            </div>

            <dl class="mt-5 grid gap-4 border-t border-borde pt-4 sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-suave">Materias</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-contenido">{{ materias.length }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-suave">Créditos cargados</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-contenido">{{ creditosCargados }}</dd>
                </div>
                <div v-if="plan.total_creditos != null">
                    <dt class="text-xs uppercase tracking-wide text-suave">Declarados en el plan</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-contenido">{{ plan.total_creditos }}</dd>
                </div>
                <div v-if="plan.total_creditos != null">
                    <dt class="text-xs uppercase tracking-wide text-suave">Diferencia</dt>
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
        <TarjetaSeccion
            id="alta-materia"
            v-if="puedeEditar"
            titulo="Agregar materia"
            descripcion="La asignatura se crea aquí mismo y queda ligada a este plan. Para editar una ya existente usa el botón «Editar» de la malla."
            :icono="ICONOS.libro"
        >
            <template #insignia>
                <div class="flex items-center gap-2">
                    <!--
                        Excluyentes: las dos son formas de dar de alta lo mismo, y
                        con las dos abiertas la tarjeta pedía dos veces los mismos
                        datos con dos formatos distintos.
                    -->
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        @click="alternarCarga"
                    >
                        {{ mostrarCarga ? 'Ocultar' : 'Cargar desde Excel' }}
                    </button>
                    <BotonAccion v-if="!mostrarAlta" variante="nuevo" texto="Agregar materia" @click="abrirAlta" />
                </div>
            </template>

            <!--
                Con los dos paneles cerrados el cuerpo quedaba en blanco. Se dice
                por dónde entrar, y de paso lo único que hay que decidir antes:
                una materia se captura o se cargan muchas de golpe.
            -->
            <p v-if="!mostrarCarga && !mostrarAlta" class="text-sm text-suave">
                Captura una materia con «Agregar materia», o sube varias de golpe con la
                plantilla de Excel. También puedes usar el «+» de cada periodo en la malla
                de abajo para dar de alta ya apuntando a ese semestre.
            </p>

            <!-- Carga de asignaturas por Excel -->
            <div v-if="mostrarCarga" class="space-y-4">
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
                    v-if="erroresCarga.length && ultimaCarga === 'asignaturas'"
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

            <form v-if="mostrarAlta" class="space-y-4" @submit.prevent="guardar">
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
                    <!--
                        Un solo campo, y estaba en una rejilla de cuatro columnas:
                        tres cuartos de la fila en blanco. Va a su ancho, y al lado
                        lo que ese periodo ya tiene, que es lo que uno quiere saber
                        justo antes de meterle otra materia.
                    -->
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="w-full sm:w-72">
                            <CampoSelect
                                v-model="form.periodo"
                                etiqueta="Periodo"
                                :opciones="opcionesPeriodo"
                                vacio="Sin periodo fijo (optativas)"
                                :error="form.errors.periodo"
                            />
                        </div>
                        <p class="pb-2 text-sm text-suave">{{ resumenDelPeriodo }}</p>
                    </div>
                </div>

                <div class="flex items-end gap-2">
                    <BotonPrincipal :procesando="form.processing" texto="Agregar" icono="crear" />
                    <button
                        type="button"
                        class="rounded-lg border border-borde px-4 py-2 text-sm text-contenido hover:bg-fondo"
                        @click="mostrarAlta = false; form.reset();"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </TarjetaSeccion>

        <!-- Carga de historial académico del plan por Excel -->
        <section v-if="puedeEditar" class="tarjeta p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-contenido">Cargar historial académico de este plan</h2>
                    <p class="mt-1 text-sm text-suave">
                        Sube las calificaciones de los alumnos de este plan. La plantilla trae sus materias
                        como desplegable; el estatus (aprobada/reprobada) se deriva de la calificación.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm font-medium"
                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    @click="mostrarHistorial = !mostrarHistorial"
                >
                    {{ mostrarHistorial ? 'Ocultar' : 'Cargar historial académico' }}
                </button>
            </div>

            <div v-if="mostrarHistorial" class="mt-5 space-y-4 border-t border-borde pt-5">
                <a
                    :href="`/academico/planes/${plan.id}/plantilla-historial`"
                    class="inline-flex items-center gap-2 text-sm font-medium"
                    :style="{ color: 'var(--color-acento)' }"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M12 3v13.5m0 0 4.5-4.5M12 16.5 7.5 12" /></svg>
                    Descargar plantilla de historial académico (.xlsx)
                </a>

                <ZonaArchivo
                    accept=".xlsx"
                    texto="Arrastra la plantilla llena (.xlsx) o haz clic para seleccionarla"
                    ayuda="Cada fila es una materia cursada por una matrícula; se valida antes de crear nada."
                    :cargado="null"
                    :ocupado="cargaHistorial.processing"
                    @archivo="subirHistorial"
                />

                <div
                    v-if="erroresCarga.length && ultimaCarga === 'historial'"
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
                                            v-if="esOptativa(materia.tipo)"
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
                                    <!-- Tipo del catálogo de la asignatura; la optativa se
                                         resalta con el acento porque es la que sale del
                                         orden por periodos. -->
                                    <span
                                        v-if="materia.tipo"
                                        class="rounded-full px-2 py-1 text-xs"
                                        :class="{ 'elegido-acento': !esOptativa(materia.tipo) }"
                                        :style="esOptativa(materia.tipo) ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' } : {}"
                                    >
                                        {{ materia.tipo }}
                                    </span>
                                    <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">—</span>
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

        <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
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
