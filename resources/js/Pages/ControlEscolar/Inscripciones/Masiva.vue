<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import SelectorVista from '@/Components/SelectorVista.vue';
import { toast } from 'vue-sonner';

interface Candidato {
    id: number;
    matricula: string;
    nombre: string | null;
    carrera: string | null;
    campus: string | null;
    mismo_campus: boolean;
    periodo_actual: number | null;
    foto: string | null;
    sugerido: boolean;
}

interface DetalleMateria {
    inscripcion_id: number;
    asignatura_grupo_id: number;
    materia: string | null;
    tipo_evaluacion: string | null;
}

interface Inscrito {
    id: number;
    matricula: string;
    nombre: string | null;
    carrera: string | null;
    foto: string | null;
    materias: number;
    total_materias: number;
    completo: boolean;
    detalle: DetalleMateria[];
}

const props = defineProps<{
    ciclos: { id: number; etiqueta: string }[];
    grupos: { id: number; etiqueta: string }[];
    seleccion: { ciclo_id: number | null; grupo_id: number | null };
    grupo: {
        id: number;
        clave: string;
        plan: string | null;
        ciclo: string | null;
        ciclo_id: number;
        campus: string | null;
        turno: string | null;
        cupo: number | null;
        periodo_objetivo: number | null;
        planes_admitidos: string[];
        materias: { id: number; clave_en_plan: string | null; nombre: string | null; periodo: number | null }[];
    } | null;
    candidatos: Candidato[];
    inscritos: Inscrito[];
    tiposEvaluacion: { id: number; nombre: string }[];
    puedeInscribir: boolean;
}>();

const cicloId = ref(props.seleccion.ciclo_id);
const grupoId = ref(props.seleccion.grupo_id);

// Al cambiar de ciclo, el grupo elegido deja de aplicar.
watch(cicloId, (nuevo, viejo) => {
    if (nuevo !== viejo) {
        grupoId.value = null;
    }
});
watch([cicloId, grupoId], () => {
    router.get(
        '/escolar/inscripciones/masiva',
        { ciclo_id: cicloId.value, grupo_id: grupoId.value },
        { preserveState: true, replace: true },
    );
});

// Selección de alumnos a inscribir (por id de matrícula).
const seleccionados = ref<Set<number>>(new Set());

function alternar(id: number): void {
    seleccionados.value.has(id) ? seleccionados.value.delete(id) : seleccionados.value.add(id);
    seleccionados.value = new Set(seleccionados.value);
}

function limpiar(): void {
    seleccionados.value = new Set();
}

// Buscador: filtra los candidatos por nombre o matrícula; los sugeridos primero.
const busqueda = ref('');

/*
 * «Sugerido» es el alumno cuyo grado coincide con el del grupo, y en la carga
 * normal de un ciclo son casi todos. El filtro arranca en ellos porque inscribir
 * a un grupo de tercero es, casi siempre, meter a los de tercero; ver de entrada
 * la lista completa de la carrera obliga a distinguir a ojo quién toca.
 */
const soloSugeridos = ref(true);

/*
 * El grupo está físicamente en un campus, así que por omisión solo se ofrecen
 * los alumnos de ese campus. No es un candado: el alumno que cursa en otro
 * campus existe (movilidad, materias compartidas) y el enlace de abajo lo trae
 * a la vista. Esconderlo sin decirlo dejaría ese caso sin salida.
 */
const soloDelCampus = ref(true);

const deOtroCampus = computed(() => props.candidatos.filter((c) => !c.mismo_campus).length);

const delCampus = computed(() =>
    soloDelCampus.value ? props.candidatos.filter((c) => c.mismo_campus) : props.candidatos,
);

const totalSugeridos = computed(() => delCampus.value.filter((c) => c.sugerido).length);

const filtrados = computed(() => {
    const q = busqueda.value.trim().toLowerCase();

    // El filtro de sugeridos se ignora cuando no hay ninguno (grupo sin grado
    // objetivo, o nadie en ese grado): mostrar cero candidatos parecería un
    // error del sistema y en realidad solo sobra el filtro.
    const base =
        soloSugeridos.value && totalSugeridos.value > 0
            ? delCampus.value.filter((c) => c.sugerido)
            : delCampus.value;

    const lista = q
        ? base.filter(
              (c) => (c.nombre ?? '').toLowerCase().includes(q) || c.matricula.toLowerCase().includes(q),
          )
        : base;

    return [...lista].sort((a, b) => Number(b.sugerido) - Number(a.sugerido));
});

function marcarVisibles(): void {
    filtrados.value.forEach((c) => seleccionados.value.add(c.id));
    seleccionados.value = new Set(seleccionados.value);
}

const todosVisiblesMarcados = computed(
    () => filtrados.value.length > 0 && filtrados.value.every((c) => seleccionados.value.has(c.id)),
);

// Cuántos lugares quedan. El cupo se valida materia por materia en el servidor;
// aquí solo se avisa antes de que la carga rebote a medias.
const lugaresLibres = computed(() =>
    props.grupo?.cupo == null ? null : props.grupo.cupo - props.inscritos.length,
);

const excedeCupo = computed(
    () => lugaresLibres.value !== null && seleccionados.value.size > lugaresLibres.value,
);

const incompletos = computed(() => props.inscritos.filter((i) => !i.completo));

const form = useForm<{ grupo_id: number | null; matricula_oferta_ids: number[] }>({
    grupo_id: null,
    matricula_oferta_ids: [],
});

function inscribir(ids?: number[]): void {
    form.grupo_id = grupoId.value;
    form.matricula_oferta_ids = ids ?? [...seleccionados.value];
    form.post('/escolar/inscripciones/masiva', {
        preserveScroll: true,
        onSuccess: () => limpiar(),
    });
}

/** Completar a los que quedaron con materias sueltas: las que ya tienen se omiten solas. */
function completarIncompletos(): void {
    inscribir(incompletos.value.map((i) => i.id));
}

function bajaDelGrupo(inscrito: Inscrito): void {
    if (!confirm(`¿Dar de baja a ${inscrito.nombre ?? 'este alumno'} de TODAS las materias del grupo?`)) {
        return;
    }

    router.put(`/escolar/grupos/${props.grupo?.id}/alumnos/${inscrito.id}/baja`, {}, { preserveScroll: true });
}

/*
 * Gestión materia por materia.
 *
 * La carga masiva resuelve el 95 % —todos a todas las materias—, pero el caso
 * suelto existe: quien recursa una sola, quien la lleva en extraordinario, quien
 * se da de baja de una y sigue en el resto. Vive aquí, junto a los alumnos, y no
 * en el detalle del grupo, que es donde se arman las materias.
 */
const expandido = ref<number | null>(null);

function alternarDetalle(id: number): void {
    expandido.value = expandido.value === id ? null : id;
}

function bajaDeMateria(detalle: DetalleMateria, alumno: string | null): void {
    if (!confirm(`¿Dar de baja a ${alumno ?? 'este alumno'} de "${detalle.materia}"? Sigue en el resto del grupo.`)) {
        return;
    }

    router.put(`/escolar/inscripciones/${detalle.inscripcion_id}/baja`, {}, { preserveScroll: true });
}

// Alta puntual: un alumno del grupo a una materia en la que todavía no está.
const tipoOrdinaria = computed(
    () => props.tiposEvaluacion.find((t) => /ordinaria/i.test(t.nombre))?.id ?? props.tiposEvaluacion[0]?.id ?? null,
);

const formSuelta = useForm({
    matricula_oferta_id: null as number | null,
    asignatura_grupo_id: null as number | null,
    tipo_evaluacion_id: null as number | null,
});

/** Materias del grupo en las que este alumno NO está: las que se le pueden dar. */
function materiasFaltantes(inscrito: Inscrito) {
    const suyas = new Set(inscrito.detalle.map((d) => d.asignatura_grupo_id));

    return (props.grupo?.materias ?? []).filter((m) => !suyas.has(m.id));
}

function inscribirEnMateria(inscrito: Inscrito, asignaturaGrupoId: number | null): void {
    if (asignaturaGrupoId === null) {
        return;
    }

    formSuelta.matricula_oferta_id = inscrito.id;
    formSuelta.asignatura_grupo_id = asignaturaGrupoId;
    formSuelta.tipo_evaluacion_id = tipoOrdinaria.value;
    formSuelta.post('/escolar/inscripciones', {
        preserveScroll: true,
        /*
         * El rechazo llega como error de validación, no como flash, así que hay
         * que mostrarlo a mano: sin esto el botón no hacía nada visible cuando
         * el alumno no podía entrar a esa materia (seriación, cupo, otro plan),
         * y no hay peor respuesta que ninguna.
         */
        onError: (errores) => {
            toast.error(Object.values(errores).flat().join(' ') || 'No se pudo inscribir en esa materia.');
        },
    });
}

const vistaAlumnos = ref<'lista' | 'cuadricula'>('cuadricula');

function iniciales(nombre: string | null): string {
    return (nombre ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase();
}
</script>

<template>
    <Head title="Inscripción masiva" />

    <AppLayout titulo="Inscripción masiva">
        <NavEscolar />

        <!-- Selección de ciclo y grupo -->
        <section class="tarjeta p-6">
            <BotonVolver href="/escolar/grupos" texto="Grupos" class="mb-4" />

            <div class="flex flex-wrap items-end justify-between gap-4">
                <!-- Misma cascada numerada que el alta de grupo: el grupo no se
                     puede elegir antes que el ciclo, y el desplegable lo dice
                     estando bloqueado en vez de abrirse vacío. -->
                <div class="grid flex-1 gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="cicloId"
                        etiqueta="1 · Ciclo"
                        :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                        vacio="Selecciona un ciclo…"
                    />
                    <CampoSelect
                        v-model="grupoId"
                        etiqueta="2 · Grupo"
                        :opciones="grupos.map((g) => ({ valor: g.id, texto: g.etiqueta }))"
                        :deshabilitado="!cicloId"
                        :vacio="cicloId
                            ? (grupos.length ? 'Selecciona un grupo…' : 'Ese ciclo no tiene grupos')
                            : 'Elige un ciclo primero'"
                        :ayuda="cicloId && grupos.length ? `${grupos.length} grupo(s) en el ciclo.` : undefined"
                    />
                </div>

                <!-- Ya no hay «inscripción individual» aparte: inscribir a uno
                     solo se hace desde su materia, en el grupo. -->
                <p class="max-w-xs text-xs" :style="{ color: 'var(--color-suave)' }">
                    Para inscribir a un alumno en <strong>una sola materia</strong>
                    (recursamiento, extraordinario), entra a las materias del grupo
                    y usa el botón «Alumno» de esa materia.
                </p>
            </div>
        </section>

        <template v-if="grupo">
            <!-- Identidad del grupo y estado del cupo -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold">Grupo {{ grupo.clave }}</h2>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span
                                v-if="grupo.periodo_objetivo"
                                class="rounded-full px-2.5 py-1 text-xs font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                            >
                                Periodo {{ grupo.periodo_objetivo }}
                            </span>
                            <span
                                v-for="chip in [grupo.campus, grupo.turno, `${grupo.materias.length} materia(s)`]"
                                :key="String(chip)"
                                class="rounded-full px-2.5 py-1 text-xs"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }"
                            >
                                {{ chip }}
                            </span>
                        </div>

                        <!-- De qué planes admite alumnos. Con un chip por plan,
                             porque con dos carreras el texto corrido no se lee. -->
                        <p v-if="grupo.planes_admitidos.length" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Admite alumnos de:
                            <span
                                v-for="p in grupo.planes_admitidos"
                                :key="p"
                                class="rounded-full px-2 py-0.5"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                            >
                                {{ p }}
                            </span>
                        </p>
                    </div>

                    <div class="flex flex-col items-end gap-2">
                        <div class="text-right">
                            <p class="text-2xl font-semibold leading-none" :style="{ color: 'var(--color-acento)' }">
                                {{ inscritos.length }}<span v-if="grupo.cupo" class="text-base" :style="{ color: 'var(--color-suave)' }">/{{ grupo.cupo }}</span>
                            </p>
                            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ lugaresLibres !== null ? `${lugaresLibres} lugar(es) libres` : 'alumnos en el grupo' }}
                            </p>
                        </div>
                        <BotonAccion variante="ver" texto="Materias del grupo" :href="`/escolar/grupos/${grupo.id}`" />
                    </div>
                </div>

                <div v-if="!grupo.materias.length" class="mt-4 flex flex-wrap items-center gap-3 rounded-lg px-3 py-2 text-sm" :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 12%, transparent)', color: '#b45309' }">
                    <span>El grupo no tiene materias abiertas: no hay dónde inscribir.</span>
                    <BotonAccion variante="agregar" texto="Abrirle materias" :href="`/escolar/grupos/${grupo.id}`" />
                </div>
            </section>

            <!-- Quién YA está en el grupo. Va ANTES de los candidatos: al volver
                 tras una carga, lo primero que se busca es la confirmación. -->
            <section v-if="inscritos.length" class="tarjeta overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                    <div>
                        <h2 class="text-base font-semibold">Ya en el grupo ({{ inscritos.length }})</h2>
                        <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Inscritos en las {{ grupo.materias.length }} materias del grupo, salvo lo que se indique.
                        </p>
                    </div>
                    <BotonAccion
                        v-if="puedeInscribir && incompletos.length"
                        variante="agregar"
                        :texto="`Completar ${incompletos.length} incompleto(s)`"
                        @click="completarIncompletos"
                    />
                </header>

                <ul class="divide-y divide-borde border-t" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="i in inscritos" :key="i.id" :style="{ borderColor: 'var(--color-borde)' }">
                        <div class="flex items-center gap-3 px-6 py-3">
                            <img v-if="i.foto" :src="i.foto" :alt="i.nombre ?? ''" class="h-9 w-9 shrink-0 rounded-full object-cover" />
                            <span
                                v-else
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                            >
                                {{ iniciales(i.nombre) }}
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ i.nombre }}</span>
                                <span class="block truncate font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ i.matricula }}</span>
                            </span>

                            <!-- El badge abre el desglose: ahí se atiende el caso
                                 suelto (recursa una, extraordinario, baja de una
                                 sola materia) sin que estorbe al resto. -->
                            <button
                                type="button"
                                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                :style="i.completo
                                    ? { backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)', color: '#15803d' }
                                    : { backgroundColor: 'color-mix(in srgb, #f59e0b 16%, transparent)', color: '#b45309' }"
                                @click="alternarDetalle(i.id)"
                            >
                                {{ i.completo ? `${i.materias} materias` : `${i.materias} de ${i.total_materias}` }}
                                {{ expandido === i.id ? '▾' : '▸' }}
                            </button>

                            <BotonAccion
                                v-if="puedeInscribir"
                                variante="eliminar"
                                texto="Dar de baja de todo el grupo"
                                @click="bajaDelGrupo(i)"
                            />
                        </div>

                        <div
                            v-if="expandido === i.id"
                            class="border-t px-6 py-3"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 5%, transparent)' }"
                        >
                            <ul class="space-y-1">
                                <li v-for="d in i.detalle" :key="d.inscripcion_id" class="flex items-center gap-2 text-sm">
                                    <span class="min-w-0 flex-1 truncate">{{ d.materia }}</span>
                                    <span
                                        v-if="d.tipo_evaluacion"
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[11px]"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }"
                                    >
                                        {{ d.tipo_evaluacion }}
                                    </span>
                                    <BotonAccion
                                        v-if="puedeInscribir"
                                        variante="eliminar"
                                        texto="Dar de baja solo de esta materia"
                                        @click="bajaDeMateria(d, i.nombre)"
                                    />
                                </li>
                            </ul>

                            <div v-if="puedeInscribir && materiasFaltantes(i).length" class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                <span :style="{ color: 'var(--color-suave)' }">Le falta:</span>
                                <button
                                    v-for="m in materiasFaltantes(i)"
                                    :key="m.id"
                                    type="button"
                                    class="rounded-full border px-2 py-0.5 disabled:opacity-50"
                                    :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                                    :disabled="formSuelta.processing"
                                    :title="m.nombre ?? ''"
                                    @click="inscribirEnMateria(i, m.id)"
                                >
                                    + {{ m.clave_en_plan }}
                                </button>
                                <span :style="{ color: 'var(--color-suave)' }">— clic para inscribirlo en ordinario.</span>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Candidatos -->
            <section v-if="grupo.materias.length" class="tarjeta p-6">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <CampoTexto
                            v-model="busqueda"
                            etiqueta="Agregar alumnos"
                            tipo="search"
                            marcador="Nombre o matrícula…"
                        />
                    </div>
                    <div v-if="puedeInscribir" class="flex items-center gap-2">
                        <!-- Los dos filtros de esta lista viven juntos y se ven
                             igual. El de campus estaba abajo, como enlace
                             subrayado, y parecía navegación en vez de filtro. -->
                        <label
                            v-if="totalSugeridos"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: soloSugeridos ? 'var(--color-acento)' : 'var(--color-borde)', color: soloSugeridos ? 'var(--color-acento)' : 'inherit' }"
                        >
                            <input v-model="soloSugeridos" type="checkbox" class="rounded" />
                            Solo periodo {{ grupo.periodo_objetivo }} ({{ totalSugeridos }})
                        </label>
                        <label
                            v-if="deOtroCampus"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: soloDelCampus ? 'var(--color-acento)' : 'var(--color-borde)', color: soloDelCampus ? 'var(--color-acento)' : 'inherit' }"
                            :title="`Hay ${deOtroCampus} alumno(s) de otros campus que podrían cursar aquí (movilidad, materias compartidas).`"
                        >
                            <input v-model="soloDelCampus" type="checkbox" class="rounded" />
                            Solo {{ grupo.campus }}
                        </label>
                        <button
                            type="button"
                            :disabled="!filtrados.length"
                            class="rounded-lg border px-3 py-2 text-sm disabled:opacity-40"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="todosVisiblesMarcados ? limpiar() : marcarVisibles()"
                        >
                            {{ todosVisiblesMarcados ? 'Quitar todos' : `Marcar los ${filtrados.length}` }}
                        </button>
                        <!-- Cuadrícula para reconocer caras, lista para cargar
                             muchos de corrido: las dos maneras se usan. -->
                        <SelectorVista v-model="vistaAlumnos" clave="inscripcion-candidatos" />
                    </div>
                </div>

                <!-- LISTA: renglones compactos, para marcar muchos rápido. -->
                <ul v-if="filtrados.length && vistaAlumnos === 'lista'" class="mt-5 divide-y divide-borde rounded-lg border" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="c in filtrados" :key="c.id" :style="{ borderColor: 'var(--color-borde)' }">
                        <label
                            class="flex cursor-pointer items-center gap-3 px-3 py-2"
                            :style="{ backgroundColor: seleccionados.has(c.id) ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)' : 'transparent' }"
                        >
                            <input type="checkbox" class="rounded" :checked="seleccionados.has(c.id)" @change="alternar(c.id)" />
                            <img v-if="c.foto" :src="c.foto" :alt="c.nombre ?? ''" class="h-8 w-8 shrink-0 rounded-full object-cover" />
                            <span
                                v-else
                                class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-semibold"
                                :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                            >
                                {{ iniciales(c.nombre) }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-sm">{{ c.nombre }}</span>
                            <span class="shrink-0 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.matricula }}</span>
                            <span v-if="c.periodo_actual" class="shrink-0 text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                Per. {{ c.periodo_actual }}
                            </span>
                            <span
                                v-if="c.sugerido"
                                class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                            >
                                Sugerido
                            </span>
                            <span
                                v-if="!c.mismo_campus"
                                class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 16%, transparent)', color: '#b45309' }"
                            >
                                {{ c.campus }}
                            </span>
                        </label>
                    </li>
                </ul>

                <!-- CUADRÍCULA: tarjetas con foto, para reconocer al alumno. -->
                <div v-else-if="filtrados.length" class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        v-for="c in filtrados"
                        :key="c.id"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition"
                        :style="{
                            borderColor: seleccionados.has(c.id) ? 'var(--color-acento)' : 'var(--color-borde)',
                            backgroundColor: seleccionados.has(c.id) ? 'color-mix(in srgb, var(--color-acento) 8%, transparent)' : 'transparent',
                        }"
                    >
                        <input type="checkbox" class="sr-only" :checked="seleccionados.has(c.id)" @change="alternar(c.id)" />
                        <img v-if="c.foto" :src="c.foto" :alt="c.nombre ?? ''" class="h-12 w-12 shrink-0 rounded-full object-cover" />
                        <span
                            v-else
                            class="grid h-12 w-12 shrink-0 place-items-center rounded-full text-sm font-semibold"
                            :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                        >
                            {{ iniciales(c.nombre) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ c.nombre }}</span>
                            <span class="block truncate font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.matricula }}</span>
                            <span class="mt-0.5 flex items-center gap-1.5">
                                <span v-if="c.periodo_actual" class="text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                    Periodo {{ c.periodo_actual }}
                                </span>
                                <span
                                    v-if="c.sugerido"
                                    class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }"
                                >
                                    Sugerido
                                </span>
                                <!-- Inscribir a alguien de otro campus se puede,
                                     pero no debe pasar por descuido. -->
                                <span
                                    v-if="!c.mismo_campus"
                                    class="rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                    :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 16%, transparent)', color: '#b45309' }"
                                >
                                    {{ c.campus }}
                                </span>
                            </span>
                        </span>
                    </label>
                </div>

                <p v-else class="mt-5 rounded-lg border border-dashed px-4 py-8 text-center text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                    <template v-if="busqueda">Ningún alumno coincide con la búsqueda.</template>
                    <template v-else-if="soloSugeridos && totalSugeridos">Nadie del periodo {{ grupo.periodo_objetivo }} está libre.</template>
                    <template v-else-if="grupo.planes_admitidos.length">
                        Ningún alumno de <strong>{{ grupo.planes_admitidos.join(' ni ') }}</strong><span v-if="soloDelCampus"> en {{ grupo.campus }}</span>
                        está activo y sin grupo en este ciclo.
                        <span class="mt-2 block text-xs">
                            Las materias abiertas son de
                            {{ grupo.planes_admitidos.length > 1 ? 'esos planes' : 'ese plan' }}: un alumno de
                            otro plan rebotaría en todas.
                        </span>
                    </template>
                    <template v-else>
                        Ningún alumno activo del nivel del grupo<span v-if="soloDelCampus"> en {{ grupo.campus }}</span>
                        está sin grupo en este ciclo.
                    </template>
                </p>

                <!-- El grupo es de un campus, pero el alumno de otro campus
                     existe (movilidad, materias compartidas). Cuando la lista
                     sale vacía por ese filtro, hay que decirlo: si no, parece
                     que no hay nadie. -->
                <p v-if="!filtrados.length && soloDelCampus && deOtroCampus" class="mt-3 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Hay {{ deOtroCampus }} alumno(s) de otros campus: quita el filtro
                    «Solo {{ grupo.campus }}» de arriba para verlos.
                </p>

                <div v-if="puedeInscribir" class="mt-6 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <p
                        v-if="excedeCupo"
                        class="mb-3 rounded-lg px-3 py-2 text-sm"
                        :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 12%, transparent)', color: '#b45309' }"
                    >
                        Seleccionaste {{ seleccionados.size }} y solo quedan {{ lugaresLibres }} lugar(es):
                        los que excedan el cupo rebotarán materia por materia.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <BotonPrincipal
                            :procesando="form.processing"
                            :deshabilitado="!seleccionados.size"
                            :texto="seleccionados.size
                                ? `Inscribir ${seleccionados.size} alumno(s) al grupo`
                                : 'Inscribir al grupo'"
                            icono="crear"
                            tipo="button"
                            @click="inscribir()"
                        />
                        <button
                            v-if="seleccionados.size"
                            type="button"
                            class="text-sm"
                            :style="{ color: 'var(--color-suave)' }"
                            @click="limpiar"
                        >
                            Quitar selección
                        </button>
                        <span v-if="seleccionados.size" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                            = {{ seleccionados.size * grupo.materias.length }} inscripciones. La materia que rebote
                            (seriación, cupo) se omite sin cancelar el resto.
                        </span>
                    </div>
                </div>
            </section>
        </template>

        <p v-else class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Elige un ciclo y un grupo para ver a los alumnos sugeridos.
        </p>
    </AppLayout>
</template>
