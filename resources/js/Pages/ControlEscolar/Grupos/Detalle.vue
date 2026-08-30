<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import CampoBuscador from '@/Components/CampoBuscador.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import SelectorVista from '@/Components/SelectorVista.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import { toast } from 'vue-sonner';

/*
 * Esta pantalla es la de las MATERIAS del grupo. Los alumnos viven en
 * «Inscribir»: listarlos aquí, materia por materia, hacía que un grupo normal
 * —seis materias, treinta alumnos— fuera un scroll de casi doscientos renglones
 * para ver seis cosas. Dos pantallas cortas se leen mejor que una larga, y las
 * dos tareas son distintas: aquí se arma el grupo, allá se puebla.
 */
interface MateriaAbierta {
    id: number;
    clave_en_plan: string | null;
    materia: string | null;
    plan: string | null;
    situacion: string | null;
    inscritos_count: number;
    docentes_asignados: { id: number; nombre: string | null; tipo: string }[];
}

interface MateriaDisponible {
    id: number;
    clave_en_plan: string;
    materia: string | null;
    plan: string | null;
    periodo: number | null;
    tipo: string;
    etiqueta: string;
}

const props = defineProps<{
    grupo: Record<string, any>;
    asignaturas: MateriaAbierta[];
    materiasDisponibles: MateriaDisponible[];
    docentes: { id: number; nombre: string }[];
    tiposEvaluacion: { id: number; nombre: string }[];
    puedeEditar: boolean;
    puedeInscribir: boolean;
}>();

/*
 * Apertura de materias: primero se acota por periodo y luego se marcan varias.
 *
 * Un plan de nueve semestres puede traer cincuenta materias, y abrir un grupo
 * casi siempre significa "las de tercero". Elegirlas de una en una en un
 * desplegable de cincuenta era el trabajo más tedioso de la pantalla.
 */
// Nombre real del periodo del plan del grupo («Semestre», «Cuatrimestre»…).
// Null cuando el grupo no tiene plan fijo (materias de varios planes): ahí se
// cae al genérico «Periodo».
const unidadPeriodo = computed(() => props.grupo.unidad_periodo ?? 'Periodo');

// Si el grupo trae PLAN FIJO, sus materias pendientes del grado arrancan ya
// marcadas: abrir un grupo de tercero casi siempre es abrir las de tercero, y
// así solo hay que confirmar.
//
// Sin plan fijo NO se premarca nada, aunque el grado exista: ahí las materias
// disponibles son las de tercero de TODAS los programas académicos del campus —más de cien—
// y premarcarlas convertiría el botón en una trampa de un clic. Cuando no hay
// plan que acote, la elección tiene que ser deliberada.
const materiasDelPeriodoInicial = props.grupo.plan && props.grupo.semestre
    ? props.materiasDisponibles.filter((m) => m.periodo === props.grupo.semestre).map((m) => m.id)
    : [];

const formMateria = useForm({ plan_materia_ids: materiasDelPeriodoInicial });

/*
 * El panel de abrir materias arranca CERRADO cuando el grupo ya tiene materias:
 * a partir de ahí la pantalla se consulta más de lo que se edita, y un
 * formulario largo permanentemente abierto empuja hacia abajo lo que se viene a
 * ver. En un grupo recién creado arranca abierto, que es justo lo que toca.
 */
const agregando = ref(props.asignaturas.length === 0);

function alternarAgregar(): void {
    agregando.value = !agregando.value;
}

const vista = ref<'lista' | 'cuadricula'>('lista');

// El filtro arranca en el periodo del grupo (si lo tiene): abrir materias de un
// grupo de tercero casi siempre significa abrir las de tercero. Si ese periodo
// no tiene materias pendientes, se deja en «todos» para no mostrar vacío.
const periodoFiltro = ref<number | null>(props.grupo.semestre ?? null);

const periodosDisponibles = computed(() => {
    const periodos = [...new Set(props.materiasDisponibles.map((m) => m.periodo))]
        .filter((p): p is number => p !== null)
        .sort((a, b) => a - b);

    return periodos;
});

const materiasDelPeriodo = computed(() =>
    periodoFiltro.value === null
        ? props.materiasDisponibles
        : props.materiasDisponibles.filter((m) => m.periodo === periodoFiltro.value),
);

const formDocente = useForm({ persona_id: null as number | null, tipo: 'titular' });
const asignandoEn = ref<number | null>(null);

/*
 * Inscribir a UN alumno en UNA materia.
 *
 * Vive aquí, en la materia, porque es donde se piensa el caso: el que recursa
 * una sola, el que la lleva en extraordinario, el que entra tarde. La carga
 * masiva —todos a todas las materias— es la otra pantalla; mezclarlas obligaba
 * a entrar por «inscripción masiva» para inscribir a uno solo, que es
 * exactamente lo que confundía.
 */
const inscribiendoEn = ref<number | null>(null);

const tipoOrdinaria = computed(
    () => props.tiposEvaluacion.find((t) => /ordinaria/i.test(t.nombre))?.id ?? props.tiposEvaluacion[0]?.id ?? null,
);

const formAlumno = useForm({
    matricula_oferta_id: null as number | null,
    asignatura_grupo_id: null as number | null,
    tipo_evaluacion_id: null as number | null,
});

function alternarInscribir(materiaId: number): void {
    inscribiendoEn.value = inscribiendoEn.value === materiaId ? null : materiaId;
    formAlumno.reset();
    formAlumno.clearErrors();
    formAlumno.asignatura_grupo_id = materiaId;
    formAlumno.tipo_evaluacion_id = tipoOrdinaria.value;
}

function inscribirAlumno(): void {
    formAlumno.post('/escolar/inscripciones', {
        preserveScroll: true,
        onSuccess: () => {
            formAlumno.reset();
            formAlumno.asignatura_grupo_id = inscribiendoEn.value;
            formAlumno.tipo_evaluacion_id = tipoOrdinaria.value;
        },
        // El rechazo viaja como error de validación, no como flash: sin esto el
        // botón no daría señal de nada cuando el alumno no puede entrar.
        onError: (errores) => toast.error(Object.values(errores).flat().join(' ')),
    });
}

/*
 * Miniatura de la materia.
 *
 * Las asignaturas no tienen imagen y no la van a tener: lo que identifica a una
 * materia es su clave. El mosaico de color la vuelve reconocible de un vistazo
 * —el color sale de la propia clave, así que «DER0301» se ve siempre igual— sin
 * inventar un catálogo de imágenes que nadie va a mantener.
 */
function tonoDe(texto: string | null): number {
    let acumulado = 0;

    for (const caracter of texto ?? '?') {
        acumulado = (Math.imul(acumulado, 31) + caracter.charCodeAt(0)) | 0;
    }

    /*
     * El tono se separa por el ángulo áureo (137.5°) en vez de tomar el hash
     * módulo 360 directamente.
     *
     * Las materias de un mismo plan tienen claves consecutivas —ISC0101,
     * ISC0102, ISC0103— cuyos hashes difieren en uno, y con el módulo a secas
     * caían en tonos contiguos: seis mosaicos del mismo azul, que es
     * exactamente lo que la miniatura debía evitar. Multiplicar por el ángulo
     * áureo manda cada clave consecutiva al lado opuesto de la rueda.
     */
    return (Math.abs(acumulado) * 137.508) % 360;
}

function colorMateria(clave: string | null): { backgroundColor: string; color: string } {
    const tono = tonoDe(clave);

    return {
        backgroundColor: `oklch(0.90 0.07 ${tono})`,
        color: `oklch(0.40 0.14 ${tono})`,
    };
}

/** Las letras de la clave (sin los dígitos): «DER0301» → «DER». */
function siglaDe(clave: string | null): string {
    const letras = (clave ?? '').replace(/[^A-Za-zÁÉÍÓÚÑ]/gi, '');

    return (letras.slice(0, 3) || (clave ?? '?').slice(0, 3)).toUpperCase();
}

const sinDocente = computed(() => props.asignaturas.filter((a) => !a.docentes_asignados.length).length);

function abrirMaterias(): void {
    formMateria.post(`/escolar/grupos/${props.grupo.id}/materias`, {
        preserveScroll: true,
        onSuccess: () => {
            formMateria.reset();
            // Cerrar el panel al terminar deja a la vista lo que acaba de pasar:
            // la lista de materias abiertas, que es la confirmación real. Dejarlo
            // abierto tapa el resultado con el formulario que ya se usó.
            agregando.value = false;
        },
    });
}

/**
 * Docentes que se le pueden asignar a una materia. Los que ya la imparten
 * siguen visibles pero bloqueados, con su papel al lado: verlos marcados
 * explica por qué no se pueden elegir; que desaparecieran haría dudar de si
 * están dados de alta.
 */
function docentesPara(asignatura: MateriaAbierta) {
    return props.docentes.map((d) => {
        const asignado = asignatura.docentes_asignados.find((a) => a.id === d.id);

        return {
            valor: d.id,
            texto: d.nombre ?? '',
            deshabilitada: asignado !== undefined,
            razon: asignado ? `ya es ${asignado.tipo}` : undefined,
        };
    });
}

function alternarAsignar(materiaId: number): void {
    asignandoEn.value = asignandoEn.value === materiaId ? null : materiaId;
    formDocente.reset();
    formDocente.clearErrors();
}

function quitarMateria(asignatura: MateriaAbierta): void {
    const aviso = asignatura.inscritos_count
        ? `"${asignatura.materia}" tiene ${asignatura.inscritos_count} alumno(s) inscritos. ¿Quitarla del grupo?`
        : `¿Quitar "${asignatura.materia}" del grupo?`;

    if (!confirm(aviso)) {
        return;
    }

    router.delete(`/escolar/grupos/${props.grupo.id}/materias/${asignatura.id}`, { preserveScroll: true });
}

/*
 * Asignar el MISMO docente a varias materias de un tirón.
 *
 * Abrir un grupo son diez o doce materias y el aviso «11 sin docente» se
 * resolvía con once diálogos idénticos: elegir al mismo profesor once veces. Al
 * empezar un ciclo eso se multiplica por todos los grupos de la escuela.
 */
const enLote = ref(false);
const formLote = useForm({ persona_id: null as number | null, tipo: 'titular', asignatura_ids: [] as number[] });

function alternarLote(): void {
    enLote.value = !enLote.value;
    formLote.reset();
    formLote.clearErrors();

    // Al abrir vienen marcadas las que no tienen docente, que es a lo que se
    // entra a esta pantalla: el resto se marca a mano si hace falta.
    if (enLote.value) {
        formLote.asignatura_ids = props.asignaturas.filter((a) => !a.docentes_asignados.length).map((a) => a.id);
    }
}

function alternarMateriaDelLote(id: number): void {
    formLote.asignatura_ids = formLote.asignatura_ids.includes(id)
        ? formLote.asignatura_ids.filter((x) => x !== id)
        : [...formLote.asignatura_ids, id];
}

function asignarEnLote(): void {
    formLote.post(`/escolar/grupos/${props.grupo.id}/docentes-en-lote`, {
        preserveScroll: true,
        onSuccess: () => {
            formLote.reset();
            enLote.value = false;
        },
    });
}

function asignarDocente(asignaturaId: number): void {
    formDocente.post(`/escolar/grupos/${props.grupo.id}/materias/${asignaturaId}/docentes`, {
        preserveScroll: true,
        onSuccess: () => {
            formDocente.reset();
            asignandoEn.value = null;
        },
    });
}

/**
 * Quitar a un docente de una materia (por si se cargó al equivocado).
 * «Cambiar» es quitar el que sobra y asignar el correcto: no hace falta un
 * flujo aparte.
 */
function quitarDocente(asignaturaId: number, personaId: number, nombre: string | null): void {
    if (!confirm(`¿Quitar a ${nombre ?? 'este docente'} de la materia?`)) {
        return;
    }

    router.delete(`/escolar/grupos/${props.grupo.id}/materias/${asignaturaId}/docentes/${personaId}`, {
        preserveScroll: true,
    });
}

const urlInscribir = computed(
    () => `/escolar/inscripciones/masiva?ciclo_id=${props.grupo.ciclo_id}&grupo_id=${props.grupo.id}`,
);
</script>

<template>
    <Head :title="`Grupo ${grupo.clave}`" />

    <AppLayout titulo="Control escolar">
        <PestanasSeccion />

        <!-- Cabecera: identidad del grupo de un vistazo -->
        <section class="tarjeta p-6">
            <!-- El volver va arriba a la izquierda, antes del título, igual que
                 en todas las pantallas de detalle. -->
            <BotonVolver href="/escolar/grupos" texto="Grupos" />

            <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-contenido">{{ grupo.nombre ?? grupo.clave }}</h2>
                        <PildoraEstado :texto="grupo.situacion" />
                    </div>
                    <p class="mt-0.5 font-mono text-xs text-suave">{{ grupo.clave }}</p>

                    <!-- El NIVEL y el GRADO son la identidad del grupo, así que
                         van al frente y no perdidos en una línea de texto. -->
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span v-if="grupo.nivel" class="rounded-full px-2.5 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                            {{ grupo.nivel }}
                        </span>
                        <span v-if="grupo.semestre" class="rounded-full px-2.5 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                            {{ unidadPeriodo }} {{ grupo.semestre }}
                        </span>
                        <span v-if="grupo.turno" class="rounded-full px-2.5 py-1 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }">
                            {{ grupo.turno }}
                        </span>
                    </div>

                    <p class="mt-3 text-sm text-suave">
                        Ciclo {{ grupo.ciclo }} · {{ grupo.campus }}
                        <span v-if="grupo.plan"> · {{ grupo.plan }}</span>
                        <span v-else> · sin plan fijo</span>
                    </p>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <!-- La ocupación es lo primero que se pregunta de un grupo y
                         se contesta sin listar a nadie. -->
                    <p class="text-2xl font-semibold leading-none" :style="{ color: 'var(--color-acento)' }">
                        {{ grupo.alumnos_count }}<span class="text-base" :style="{ color: 'var(--color-suave)' }">/{{ grupo.cupo }}</span>
                    </p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">alumnos inscritos</p>

                    <div class="mt-1 flex items-center gap-2">
                        <BotonAccion
                            v-if="puedeInscribir && asignaturas.length"
                            variante="agregar"
                            texto="Inscribir alumnos"
                            :href="urlInscribir"
                        />
                        <BotonAccion v-if="puedeEditar" variante="editar" texto="Editar grupo" :href="`/escolar/grupos/${grupo.id}/edit`" />
                    </div>
                </div>
            </div>

            <!-- El grado no se mueve solo: es una decisión que se toma editando
                 el grupo, no un efecto colateral de abrirle materias. -->
            <p v-if="puedeEditar" class="mt-4 border-t border-borde pt-3 text-xs text-suave">
                El {{ unidadPeriodo.toLowerCase() }} del grupo es <strong>{{ grupo.semestre }}</strong> y no cambia
                al abrirle materias de otro: para modificarlo, edita el grupo.
                Los alumnos se administran en <Link :href="urlInscribir" class="underline" :style="{ color: 'var(--color-acento)' }">Inscribir</Link>.
            </p>
        </section>

        <!-- Materias del grupo -->
        <section class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-contenido">
                        Materias del grupo ({{ asignaturas.length }})
                    </h2>
                    <p class="mt-0.5 text-sm text-suave">
                        <template v-if="sinDocente">
                            <span class="text-amber-600">{{ sinDocente }} sin docente</span> — nadie podría firmar esas actas.
                        </template>
                        <template v-else-if="asignaturas.length">Todas con docente asignado.</template>
                        <template v-else>Abrir una materia es lo que la vuelve inscribible en este ciclo.</template>
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <SelectorVista v-if="asignaturas.length" v-model="vista" clave="grupo-materias" />
                    <button
                        v-if="puedeEditar && asignaturas.length && docentes.length"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors"
                        :style="enLote
                            ? { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }
                            : { borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        @click="alternarLote"
                    >
                        {{ enLote ? 'Cerrar' : 'Asignar docente a varias' }}
                    </button>
                    <button
                        v-if="puedeEditar && materiasDisponibles.length"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors"
                        :style="agregando
                            ? { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }
                            : { borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        @click="alternarAgregar"
                    >
                        <svg v-if="!agregando" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        {{ agregando ? 'Cerrar' : 'Agregar materias' }}
                    </button>
                    <span v-else-if="puedeEditar" class="text-xs text-amber-600">
                        No hay materias por abrir.
                    </span>
                </div>
            </header>

            <!--
                Panel de asignación en lote.
                Arranca con las materias sin docente ya marcadas: es a lo que se
                entra aquí. Las que ya tienen titular se pueden marcar igual —el
                servidor las omite e informa cuántas—, porque obligar a
                deseleccionarlas a mano devuelve el trabajo que este panel quita.
            -->
            <form
                v-if="enLote && puedeEditar"
                class="space-y-4 border-t border-borde px-6 py-5"
                @submit.prevent="asignarEnLote"
            >
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-64 flex-1">
                        <CampoBuscador
                            v-model="formLote.persona_id"
                            etiqueta="Docente"
                            :opciones="docentes.map((d) => ({ valor: d.id, texto: d.nombre ?? '' }))"
                            marcador="Busca por nombre o apellido…"
                            vacio="No hay docentes dados de alta."
                            :error="formLote.errors.persona_id"
                        />
                    </div>
                    <div class="w-40">
                        <CampoSelect
                            v-model="formLote.tipo"
                            etiqueta="Tipo"
                            :opciones="[
                                { valor: 'titular', texto: 'Titular' },
                                { valor: 'adjunto', texto: 'Adjunto' },
                            ]"
                            :error="formLote.errors.tipo"
                        />
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium text-contenido">
                        ¿A cuáles?
                        <span class="font-normal text-suave">
                            {{ formLote.asignatura_ids.length }} de {{ asignaturas.length }} seleccionadas
                        </span>
                    </p>

                    <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="a in asignaturas"
                            :key="a.id"
                            class="flex items-start gap-2 rounded-lg border border-borde px-3 py-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                class="mt-0.5"
                                :checked="formLote.asignatura_ids.includes(a.id)"
                                @change="alternarMateriaDelLote(a.id)"
                            >
                            <span class="min-w-0">
                                <span class="block truncate">{{ a.materia }}</span>
                                <!-- Quién la da ya, para no asignar a ciegas. -->
                                <span class="block truncate text-xs text-suave">
                                    <template v-if="a.docentes_asignados.length">
                                        {{ a.docentes_asignados.map((d) => `${d.nombre} (${d.tipo})`).join(', ') }}
                                    </template>
                                    <template v-else>Sin docente</template>
                                </span>
                            </span>
                        </label>
                    </div>

                    <p v-if="formLote.errors.asignatura_ids" class="mt-1 text-xs text-red-600">
                        {{ formLote.errors.asignatura_ids }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <BotonPrincipal
                        :procesando="formLote.processing"
                        :deshabilitado="!formLote.asignatura_ids.length"
                        texto="Asignar a las seleccionadas"
                        icono="crear"
                    />
                    <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="alternarLote">
                        Cancelar
                    </button>
                </div>
            </form>

            <!-- Panel de abrir materias, colapsado -->
            <form v-if="agregando && puedeEditar" class="space-y-4 border-t border-borde px-6 py-5" @submit.prevent="abrirMaterias">
                <div v-if="periodosDisponibles.length" class="sm:max-w-xs">
                    <CampoSelect
                        v-model="periodoFiltro"
                        :etiqueta="`Filtrar por ${unidadPeriodo.toLowerCase()}`"
                        :opciones="periodosDisponibles.map((p) => ({ valor: p, texto: `${unidadPeriodo} ${p}` }))"
                        :vacio="`Todos los ${unidadPeriodo.toLowerCase()}s`"
                        :ayuda="`El grupo es de ${unidadPeriodo.toLowerCase()} ${grupo.semestre}; puedes abrirle materias de otro sin que su ${unidadPeriodo.toLowerCase()} cambie.`"
                    />
                </div>

                <CampoCasillas
                    v-model="formMateria.plan_materia_ids"
                    :etiqueta="`Materias disponibles (${materiasDelPeriodo.length})`"
                    :opciones="materiasDelPeriodo.map((m) => ({
                        valor: m.id,
                        texto: `${m.clave_en_plan} · ${m.materia ?? ''}`,
                        // El PLAN va en la ayuda y no es adorno: en un grupo sin
                        // plan fijo la misma materia aparece una vez por cada
                        // plan que la incluye, con clave y nombre idénticos. Sin
                        // el plan a la vista son opciones indistinguibles.
                        ayuda: [
                            m.plan,
                            m.periodo ? `${unidadPeriodo.toLowerCase()} ${m.periodo}` : null,
                            m.tipo,
                        ].filter(Boolean).join(' · '),
                    }))"
                    :error="formMateria.errors.plan_materia_ids"
                    vacio="No hay materias disponibles en este periodo."
                />

                <div class="flex items-center gap-3">
                    <BotonPrincipal
                        :procesando="formMateria.processing"
                        :deshabilitado="formMateria.plan_materia_ids.length === 0"
                        :texto="formMateria.plan_materia_ids.length > 1 ? `Abrir ${formMateria.plan_materia_ids.length} materias` : 'Abrir materia'"
                        icono="crear"
                    />
                    <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="alternarAgregar">
                        Cancelar
                    </button>
                </div>
            </form>

            <!-- LISTA: una materia por renglón. Se lee de corrido y compara. -->
            <ul v-if="asignaturas.length && vista === 'lista'" class="divide-y divide-borde border-t border-borde">
                <li v-for="asignatura in asignaturas" :key="asignatura.id">
                    <!-- Renglones con aire: una docena de materias con tres
                         acciones cada una se lee como un amontonamiento si van
                         apretadas. -->
                    <div class="flex flex-wrap items-center gap-4 px-6 py-4">
                        <span
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-lg text-[11px] font-bold tracking-tight"
                            :style="colorMateria(asignatura.clave_en_plan)"
                        >
                            {{ siglaDe(asignatura.clave_en_plan) }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <!-- El nombre entra a la materia: quién la cursa,
                                 quién la da y cómo van. Antes había que ir al
                                 listado de alumnos y filtrar a mano. -->
                            <Link
                                :href="`/escolar/grupos/${grupo.id}/materias/${asignatura.id}`"
                                class="block truncate text-sm font-medium text-contenido hover:underline"
                            >
                                {{ asignatura.materia }}
                            </Link>
                            <span class="block truncate text-xs text-suave">
                                <span class="font-mono">{{ asignatura.clave_en_plan }}</span> · {{ asignatura.plan }}
                            </span>
                        </span>

                        <!-- Docentes: lo que de verdad hay que revisar aquí. -->
                        <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                            <span v-if="!asignatura.docentes_asignados.length" class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 16%, transparent)', color: '#b45309' }">
                                Sin docente
                            </span>
                            <!-- El PAPEL va escrito, no solo insinuado por el
                                 color: quién firma el acta es el titular, y eso
                                 tiene que leerse de un vistazo bajando la lista,
                                 no adivinarse por el tono ni buscarse en un
                                 tooltip. -->
                            <span
                                v-for="d in asignatura.docentes_asignados"
                                :key="d.id"
                                class="group inline-flex items-center gap-1.5 rounded-full py-0.5 pl-1 pr-2 text-[11px]"
                                :style="d.tipo === 'titular'
                                    ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }
                                    : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }"
                            >
                                <span
                                    class="rounded-full px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide"
                                    :style="d.tipo === 'titular'
                                        ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                                        : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 45%, transparent)', color: 'var(--color-superficie)' }"
                                >
                                    {{ d.tipo === 'titular' ? 'Titular' : 'Adjunto' }}
                                </span>
                                {{ d.nombre }}
                                <button v-if="puedeEditar" type="button" class="opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-600" @click="quitarDocente(asignatura.id, d.id, d.nombre)">×</button>
                            </span>
                        </span>

                        <span class="shrink-0 tabular-nums text-xs text-suave">{{ asignatura.inscritos_count }} alumno(s)</span>

                        <span v-if="puedeEditar" class="flex shrink-0 items-center gap-1">
                            <BotonAccion
                                v-if="puedeInscribir"
                                :variante="inscribiendoEn === asignatura.id ? 'cerrar' : 'agregar'"
                                texto="Alumno"
                                :icono-al-final="inscribiendoEn === asignatura.id"
                                @click="alternarInscribir(asignatura.id)"
                            />
                            <BotonAccion
                                :variante="asignandoEn === asignatura.id ? 'cerrar' : 'agregar'"
                                texto="Docente"
                                :icono-al-final="asignandoEn === asignatura.id"
                                @click="alternarAsignar(asignatura.id)"
                            />
                            <BotonAccion variante="eliminar" texto="Quitar materia" @click="quitarMateria(asignatura)" />
                        </span>
                    </div>

                    <!-- Inscribir a UN alumno en ESTA materia. -->
                    <form
                        v-if="inscribiendoEn === asignatura.id"
                        class="panel-accion flex flex-wrap items-end gap-3 border-t border-borde px-6 py-4"
                        @submit.prevent="inscribirAlumno"
                    >
                        <div class="min-w-64 flex-1">
                            <BuscadorRemoto
                                v-model="formAlumno.matricula_oferta_id"
                                :url="`/escolar/grupos/${grupo.id}/materias/${asignatura.id}/candidatos`"
                                etiqueta="Alumno"
                                marcador="Escribe matrícula o nombre…"
                                :error="formAlumno.errors.matricula_oferta_id ?? formAlumno.errors.asignatura_grupo_id"
                                ayuda="Solo aparecen los que pueden entrar a esta materia."
                            />
                        </div>
                        <div class="w-48">
                            <CampoSelect
                                v-model="formAlumno.tipo_evaluacion_id"
                                etiqueta="Tipo"
                                :opciones="tiposEvaluacion.map((t) => ({ valor: t.id, texto: t.nombre }))"
                                :error="formAlumno.errors.tipo_evaluacion_id"
                                ayuda="Ordinario, extraordinario…"
                            />
                        </div>
                        <BotonPrincipal
                            :procesando="formAlumno.processing"
                            :deshabilitado="!formAlumno.matricula_oferta_id"
                            texto="Inscribir"
                            icono="crear"
                        />
                    </form>

                    <form
                        v-if="asignandoEn === asignatura.id"
                        class="panel-accion flex flex-wrap items-end gap-3 border-t border-borde px-6 py-4"
                        @submit.prevent="asignarDocente(asignatura.id)"
                    >
                        <div class="min-w-64 flex-1">
                            <CampoBuscador
                                v-model="formDocente.persona_id"
                                etiqueta="Docente"
                                :opciones="docentesPara(asignatura)"
                                marcador="Busca por nombre o apellido…"
                                vacio="No hay docentes dados de alta."
                                :error="formDocente.errors.persona_id"
                            />
                        </div>
                        <div class="w-40">
                            <CampoSelect
                                v-model="formDocente.tipo"
                                etiqueta="Tipo"
                                :opciones="[
                                    { valor: 'titular', texto: 'Titular' },
                                    { valor: 'adjunto', texto: 'Adjunto' },
                                ]"
                                :error="formDocente.errors.tipo"
                            />
                        </div>
                        <BotonPrincipal :procesando="formDocente.processing" texto="Asignar" />
                        <p v-if="!docentes.length" class="w-full text-xs text-amber-600">
                            No hay docentes registrados todavía.
                        </p>
                    </form>
                </li>
            </ul>

            <!-- CUADRÍCULA: la materia se reconoce por su mosaico de color. -->
            <div v-else-if="asignaturas.length" class="grid gap-3 border-t border-borde p-6 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="asignatura in asignaturas"
                    :key="asignatura.id"
                    class="flex flex-col overflow-hidden rounded-xl border"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex items-center gap-3 p-3">
                        <span
                            class="grid h-14 w-14 shrink-0 place-items-center rounded-lg text-sm font-bold tracking-tight"
                            :style="colorMateria(asignatura.clave_en_plan)"
                        >
                            {{ siglaDe(asignatura.clave_en_plan) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <Link
                                :href="`/escolar/grupos/${grupo.id}/materias/${asignatura.id}`"
                                class="block truncate text-sm font-medium text-contenido hover:underline"
                                :title="asignatura.materia ?? ''"
                            >
                                {{ asignatura.materia }}
                            </Link>
                            <span class="block truncate font-mono text-xs text-suave">{{ asignatura.clave_en_plan }}</span>
                            <span class="block truncate text-[11px] text-suave" :title="asignatura.plan ?? ''">{{ asignatura.plan }}</span>
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5 px-3 pb-3">
                        <span v-if="!asignatura.docentes_asignados.length" class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: 'color-mix(in srgb, #f59e0b 16%, transparent)', color: '#b45309' }">
                            Sin docente
                        </span>
                        <!-- Mismo criterio que en la lista: el papel escrito. -->
                        <span
                            v-for="d in asignatura.docentes_asignados"
                            :key="d.id"
                            class="group inline-flex items-center gap-1.5 rounded-full py-0.5 pl-1 pr-2 text-[11px]"
                            :style="d.tipo === 'titular'
                                ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }
                                : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }"
                        >
                            <span
                                class="rounded-full px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide"
                                :style="d.tipo === 'titular'
                                    ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                                    : { backgroundColor: 'color-mix(in srgb, var(--color-suave) 45%, transparent)', color: 'var(--color-superficie)' }"
                            >
                                {{ d.tipo === 'titular' ? 'Titular' : 'Adjunto' }}
                            </span>
                            {{ d.nombre }}
                            <button v-if="puedeEditar" type="button" class="opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-600" @click="quitarDocente(asignatura.id, d.id, d.nombre)">×</button>
                        </span>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-2 border-t px-3 py-2" :style="{ borderColor: 'var(--color-borde)' }">
                        <span class="tabular-nums text-xs text-suave">{{ asignatura.inscritos_count }} alumno(s)</span>
                        <span v-if="puedeEditar" class="flex items-center gap-1">
                            <BotonAccion
                                v-if="puedeInscribir"
                                :variante="inscribiendoEn === asignatura.id ? 'cerrar' : 'agregar'"
                                texto="Alumno"
                                :icono-al-final="inscribiendoEn === asignatura.id"
                                @click="alternarInscribir(asignatura.id)"
                            />
                            <BotonAccion
                                :variante="asignandoEn === asignatura.id ? 'cerrar' : 'agregar'"
                                texto="Docente"
                                :icono-al-final="asignandoEn === asignatura.id"
                                @click="alternarAsignar(asignatura.id)"
                            />
                            <BotonAccion variante="eliminar" texto="Quitar materia" @click="quitarMateria(asignatura)" />
                        </span>
                    </div>

                    <form
                        v-if="inscribiendoEn === asignatura.id"
                        class="panel-accion space-y-3 border-t p-3"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @submit.prevent="inscribirAlumno"
                    >
                        <BuscadorRemoto
                            v-model="formAlumno.matricula_oferta_id"
                            :url="`/escolar/grupos/${grupo.id}/materias/${asignatura.id}/candidatos`"
                            etiqueta="Alumno"
                            marcador="Matrícula o nombre…"
                            :error="formAlumno.errors.matricula_oferta_id ?? formAlumno.errors.asignatura_grupo_id"
                        />
                        <CampoSelect
                            v-model="formAlumno.tipo_evaluacion_id"
                            etiqueta="Tipo"
                            :opciones="tiposEvaluacion.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            :error="formAlumno.errors.tipo_evaluacion_id"
                        />
                        <BotonPrincipal
                            :procesando="formAlumno.processing"
                            :deshabilitado="!formAlumno.matricula_oferta_id"
                            texto="Inscribir"
                            icono="crear"
                        />
                    </form>

                    <form
                        v-if="asignandoEn === asignatura.id"
                        class="panel-accion space-y-3 border-t p-3"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @submit.prevent="asignarDocente(asignatura.id)"
                    >
                        <CampoBuscador
                            v-model="formDocente.persona_id"
                            etiqueta="Docente"
                            :opciones="docentesPara(asignatura)"
                            marcador="Busca por nombre…"
                            vacio="No hay docentes dados de alta."
                            :error="formDocente.errors.persona_id"
                        />
                        <CampoSelect
                            v-model="formDocente.tipo"
                            etiqueta="Tipo"
                            :opciones="[
                                { valor: 'titular', texto: 'Titular' },
                                { valor: 'adjunto', texto: 'Adjunto' },
                            ]"
                            :error="formDocente.errors.tipo"
                        />
                        <BotonPrincipal :procesando="formDocente.processing" texto="Asignar" />
                    </form>
                </article>
            </div>

            <p v-else class="border-t border-borde px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Este grupo no tiene materias abiertas. Agrégale materias con el botón de arriba.
            </p>
        </section>
    </AppLayout>
</template>

<style scoped>
/*
 * Panel que se despliega al pulsar «Alumno» o «Docente» en una materia.
 *
 * Llevaba el gris de fondo de la app (`bg-fondo`), que sobre la tarjeta blanca
 * se ve turbio y pesa más que el propio formulario. Ahora no tiene relleno: se
 * queda sobre la superficie de la tarjeta y su pertenencia a esa materia la
 * marca una barra de acento a la izquierda, que es lo que hacía falta entender
 * —de qué renglón cuelga— y no un cambio de color de todo el bloque.
 */
.panel-accion {
    border-left: 3px solid var(--color-acento);
    background-color: transparent;
}
</style>
