<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import CampoBuscador from '@/Components/CampoBuscador.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Inscrito {
    inscripcion_id: number;
    matricula_oferta_id: number;
    matricula: string | null;
    alumno: string | null;
    tipo_evaluacion: string | null;
    situacion: string | null;
}

interface MateriaAbierta {
    id: number;
    clave_en_plan: string | null;
    materia: string | null;
    plan: string | null;
    situacion: string | null;
    titular: string | null;
    adjuntos: string[];
    inscritos: Inscrito[];
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
    alumnos: { id: number; nombre: string }[];
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
// disponibles son las de tercero de TODAS las carreras del campus —más de cien—
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

// --- Inscripción individual a una materia ---
// El tipo de evaluación arranca en «ordinaria» (lo normal); el select deja
// elegir extraordinaria, a título, etc.
const tipoOrdinaria = computed(
    () => props.tiposEvaluacion.find((t) => /ordinaria/i.test(t.nombre))?.id ?? props.tiposEvaluacion[0]?.id ?? null,
);

const formInscribir = useForm({
    matricula_oferta_id: null as number | null,
    asignatura_grupo_id: null as number | null,
    tipo_evaluacion_id: null as number | null,
});

// Qué materia tiene abierto su formulario de "inscribir un alumno".
const inscribiendoEn = ref<number | null>(null);

function abrirInscripcion(materiaId: number): void {
    inscribiendoEn.value = inscribiendoEn.value === materiaId ? null : materiaId;
    formInscribir.reset();
    formInscribir.clearErrors();
    formInscribir.asignatura_grupo_id = materiaId;
    formInscribir.tipo_evaluacion_id = tipoOrdinaria.value;
}

// Alumnos que aún no están (vigentes) en esta materia: el buscador no ofrece a
// quien ya está inscrito.
function alumnosPara(materia: MateriaAbierta) {
    const yaInscritos = new Set(materia.inscritos.map((i) => i.matricula_oferta_id));

    return props.alumnos
        .filter((a) => !yaInscritos.has(a.id))
        .map((a) => ({ valor: a.id, texto: a.nombre }));
}

function inscribirAlumno(): void {
    formInscribir.post('/escolar/inscripciones', {
        preserveScroll: true,
        onSuccess: () => {
            formInscribir.reset();
            inscribiendoEn.value = null;
        },
    });
}

// Baja de UNA materia (una inscripción). Conserva historia.
function bajaMateria(inscripcionId: number, alumno: string | null): void {
    if (!confirm(`¿Dar de baja a ${alumno ?? 'este alumno'} de la materia?`)) {
        return;
    }

    router.put(`/escolar/inscripciones/${inscripcionId}/baja`, {}, { preserveScroll: true });
}

// Alumnos distintos inscritos en el grupo (en cualquier materia), para poder
// darlos de baja de TODO el grupo de un tirón.
const alumnosDelGrupo = computed(() => {
    const mapa = new Map<number, { matricula_oferta_id: number; matricula: string | null; alumno: string | null; materias: number }>();

    for (const materia of props.asignaturas) {
        for (const inscrito of materia.inscritos) {
            const previo = mapa.get(inscrito.matricula_oferta_id);
            if (previo) {
                previo.materias += 1;
            } else {
                mapa.set(inscrito.matricula_oferta_id, {
                    matricula_oferta_id: inscrito.matricula_oferta_id,
                    matricula: inscrito.matricula,
                    alumno: inscrito.alumno,
                    materias: 1,
                });
            }
        }
    }

    return [...mapa.values()].sort((a, b) => (a.matricula ?? '').localeCompare(b.matricula ?? ''));
});

function bajaGrupo(matriculaOfertaId: number, alumno: string | null): void {
    if (!confirm(`¿Dar de baja a ${alumno ?? 'este alumno'} de TODO el grupo? Se dan de baja todas sus materias aquí.`)) {
        return;
    }

    router.put(`/escolar/grupos/${props.grupo.id}/alumnos/${matriculaOfertaId}/baja`, {}, { preserveScroll: true });
}

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

function quitarMateria(asignatura: MateriaAbierta): void {
    if (!confirm(`¿Quitar "${asignatura.materia}" del grupo?`)) {
        return;
    }

    router.delete(`/escolar/grupos/${props.grupo.id}/materias/${asignatura.id}`, { preserveScroll: true });
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
</script>

<template>
    <Head :title="`Grupo ${grupo.clave}`" />

    <AppLayout titulo="Control escolar">
        <NavEscolar />

        <!-- Cabecera: identidad del grupo de un vistazo -->
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-contenido">{{ grupo.nombre ?? grupo.clave }}</h2>
                        <PildoraEstado :texto="grupo.situacion" :color="grupo.situacion === 'Abierto' ? '#16a34a' : 'var(--color-suave)'" />
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
                        <span class="rounded-full px-2.5 py-1 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }">
                            Cupo {{ grupo.cupo }}
                        </span>
                    </div>

                    <p class="mt-3 text-sm text-suave">
                        Ciclo {{ grupo.ciclo }} · {{ grupo.campus }}
                        <span v-if="grupo.plan"> · {{ grupo.plan }}</span>
                        <span v-else> · sin plan fijo</span>
                    </p>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <a
                        v-if="puedeInscribir && asignaturas.length"
                        :href="`/escolar/inscripciones/masiva?ciclo_id=${grupo.ciclo_id}&grupo_id=${grupo.id}`"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                    >
                        Inscribir alumnos
                    </a>
                    <a :href="`/escolar/grupos/${grupo.id}/edit`" class="text-sm" :style="{ color: 'var(--color-acento)' }">
                        Editar grupo
                    </a>
                    <a href="/escolar/grupos" class="text-sm text-suave">← Volver a grupos</a>
                </div>
            </div>

            <!-- El grado no se mueve solo: es una decisión que se toma editando
                 el grupo, no un efecto colateral de abrirle materias. -->
            <p v-if="puedeEditar" class="mt-4 border-t border-borde pt-3 text-xs text-suave">
                El {{ unidadPeriodo.toLowerCase() }} del grupo es <strong>{{ grupo.semestre }}</strong> y no cambia
                al abrirle materias de otro: para modificarlo, edita el grupo.
            </p>
        </section>

        <!-- Abrir materias: colapsado, porque la pantalla se consulta más de lo
             que se edita. -->
        <section v-if="puedeEditar" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold text-contenido">
                        {{ agregando ? 'Agregar materias al grupo' : 'Materias del grupo' }}
                    </h2>
                    <p class="mt-0.5 text-sm text-suave">
                        Abrir una materia es lo que la vuelve inscribible en este ciclo.
                    </p>
                </div>

                <button
                    v-if="materiasDisponibles.length"
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
                <span v-else class="text-xs text-amber-600">
                    No hay materias por abrir: ya están todas, o el plan no tiene malla cargada.
                </span>
            </header>

            <form v-if="agregando" class="space-y-4 border-t border-borde px-6 py-5" @submit.prevent="abrirMaterias">
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
        </section>

        <!-- Materias abiertas -->
        <section v-if="asignaturas.length" class="tarjeta">
            <div class="border-b border-borde px-6 py-3">
                <h2 class="text-base font-semibold text-contenido">
                    Materias abiertas ({{ asignaturas.length }})
                </h2>
            </div>

            <ul class="divide-y divide-borde">
                <li v-for="asignatura in asignaturas" :key="asignatura.id" class="px-6 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-contenido">
                                <span class="font-mono text-xs text-suave">{{ asignatura.clave_en_plan }}</span>
                                · {{ asignatura.materia }}
                            </p>
                            <p class="mt-0.5 text-xs text-suave">
                                {{ asignatura.plan }} · {{ asignatura.inscritos.length }} inscrito(s)
                            </p>

                            <p v-if="!asignatura.docentes_asignados.length" class="mt-2 text-sm text-amber-600">
                                Sin docente — nadie podría firmar el acta.
                            </p>
                            <ul v-else class="mt-2 space-y-1">
                                <li
                                    v-for="d in asignatura.docentes_asignados"
                                    :key="d.id"
                                    class="flex items-center gap-2 text-sm"
                                >
                                    <span class="text-contenido">{{ d.nombre }}</span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px]"
                                        :class="d.tipo === 'titular' ? 'bg-indigo-50 text-indigo-700' : 'bg-fondo text-suave'"
                                    >
                                        {{ d.tipo }}
                                    </span>
                                    <button
                                        v-if="puedeEditar"
                                        type="button"
                                        class="text-xs text-suave hover:text-red-600"
                                        @click="quitarDocente(asignatura.id, d.id, d.nombre)"
                                    >
                                        quitar
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div v-if="puedeEditar || puedeInscribir" class="flex items-center gap-3">
                            <button
                                v-if="puedeInscribir"
                                type="button"
                                class="text-sm text-indigo-600 hover:text-indigo-700"
                                @click="abrirInscripcion(asignatura.id)"
                            >
                                Inscribir alumno
                            </button>
                            <button
                                v-if="puedeEditar"
                                type="button"
                                class="text-sm text-indigo-600 hover:text-indigo-700"
                                @click="asignandoEn = asignandoEn === asignatura.id ? null : asignatura.id"
                            >
                                Asignar docente
                            </button>
                            <button
                                v-if="puedeEditar"
                                type="button"
                                class="text-sm text-suave hover:text-red-600"
                                @click="quitarMateria(asignatura)"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>

                    <form
                        v-if="asignandoEn === asignatura.id"
                        class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-fondo p-3"
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

                    <!-- Inscribir un alumno puntual a esta materia -->
                    <form
                        v-if="inscribiendoEn === asignatura.id"
                        class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-fondo p-3"
                        @submit.prevent="inscribirAlumno"
                    >
                        <div class="min-w-64 flex-1">
                            <CampoBuscador
                                v-model="formInscribir.matricula_oferta_id"
                                etiqueta="Alumno"
                                :opciones="alumnosPara(asignatura)"
                                marcador="Busca por matrícula o nombre…"
                                vacio="No hay alumnos activos por inscribir."
                                :error="formInscribir.errors.matricula_oferta_id ?? formInscribir.errors.asignatura_grupo_id"
                            />
                        </div>
                        <div class="w-48">
                            <CampoSelect
                                v-model="formInscribir.tipo_evaluacion_id"
                                etiqueta="Tipo"
                                :opciones="tiposEvaluacion.map((t) => ({ valor: t.id, texto: t.nombre }))"
                                :error="formInscribir.errors.tipo_evaluacion_id"
                            />
                        </div>
                        <BotonPrincipal :procesando="formInscribir.processing" texto="Inscribir" />
                    </form>

                    <!-- Inscritos vigentes en esta materia -->
                    <ul v-if="asignatura.inscritos.length" class="mt-3 space-y-1">
                        <li
                            v-for="i in asignatura.inscritos"
                            :key="i.inscripcion_id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <span class="font-mono text-xs text-suave">{{ i.matricula }}</span>
                            <span class="text-contenido">{{ i.alumno }}</span>
                            <span
                                v-if="i.tipo_evaluacion"
                                class="rounded-full bg-fondo px-2 py-0.5 text-[11px] text-suave"
                            >
                                {{ i.tipo_evaluacion }}
                            </span>
                            <button
                                v-if="puedeInscribir"
                                type="button"
                                class="text-xs text-suave hover:text-red-600"
                                @click="bajaMateria(i.inscripcion_id, i.alumno)"
                            >
                                baja
                            </button>
                        </li>
                    </ul>
                </li>
            </ul>
        </section>

        <p v-else class="tarjeta px-4 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Este grupo no tiene materias abiertas. Agrégale materias con el botón de arriba.
        </p>

        <!-- Alumnos del grupo, al final: es consulta y baja excepcional, no el
             trabajo principal de esta pantalla. Dar de baja aquí lo saca de
             TODAS las materias del grupo. -->
        <section v-if="puedeInscribir && alumnosDelGrupo.length" class="tarjeta overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                <div>
                    <h2 class="text-base font-semibold text-contenido">Alumnos del grupo ({{ alumnosDelGrupo.length }})</h2>
                    <p class="mt-0.5 text-sm text-suave">
                        Inscritos en al menos una materia. Dar de baja aquí los saca de todas.
                    </p>
                </div>
                <a
                    :href="`/escolar/inscripciones/masiva?ciclo_id=${grupo.ciclo_id}&grupo_id=${grupo.id}`"
                    class="text-sm font-medium"
                    :style="{ color: 'var(--color-acento)' }"
                >
                    Inscribir más alumnos →
                </a>
            </header>

            <ul class="divide-y divide-borde border-t border-borde">
                <li v-for="a in alumnosDelGrupo" :key="a.matricula_oferta_id" class="flex items-center justify-between gap-3 px-6 py-3">
                    <span class="min-w-0 text-sm text-contenido">
                        <span class="font-medium">{{ a.alumno }}</span>
                        <span class="ml-2 font-mono text-xs text-suave">{{ a.matricula }}</span>
                        <span class="ml-2 rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)', color: 'var(--color-suave)' }">
                            {{ a.materias }} materia(s)
                        </span>
                    </span>
                    <button
                        type="button"
                        class="shrink-0 text-xs text-suave hover:text-red-600"
                        @click="bajaGrupo(a.matricula_oferta_id, a.alumno)"
                    >
                        Baja del grupo
                    </button>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
