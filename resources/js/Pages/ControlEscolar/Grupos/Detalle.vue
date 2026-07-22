<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import CampoBuscador from '@/Components/CampoBuscador.vue';

interface MateriaAbierta {
    id: number;
    clave_en_plan: string | null;
    materia: string | null;
    plan: string | null;
    situacion: string | null;
    titular: string | null;
    adjuntos: string[];
    inscritos: number;
    docentes_asignados: { id: number; tipo: string }[];
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
    puedeEditar: boolean;
}>();

/*
 * Apertura de materias: primero se acota por periodo y luego se marcan varias.
 *
 * Un plan de nueve semestres puede traer cincuenta materias, y abrir un grupo
 * casi siempre significa "las de tercero". Elegirlas de una en una en un
 * desplegable de cincuenta era el trabajo más tedioso de la pantalla.
 */
const formMateria = useForm({ plan_materia_ids: [] as number[] });

const periodoFiltro = ref<number | null>(null);

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

function abrirMaterias(): void {
    formMateria.post(`/escolar/grupos/${props.grupo.id}/materias`, {
        preserveScroll: true,
        onSuccess: () => formMateria.reset(),
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
</script>

<template>
    <Head :title="`Grupo ${grupo.clave}`" />

    <AppLayout titulo="Control escolar">
        <NavEscolar />

        <!-- Cabecera -->
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm text-slate-500">{{ grupo.clave }}</p>
                    <h2 class="text-lg font-semibold text-slate-800">{{ grupo.nombre ?? 'Grupo' }}</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Ciclo {{ grupo.ciclo }} · {{ grupo.campus }}
                        <span v-if="grupo.plan"> · {{ grupo.plan }}</span>
                        <span v-if="grupo.cupo"> · cupo {{ grupo.cupo }}</span>
                    </p>
                </div>
                <a href="/escolar/grupos" class="text-sm text-indigo-600 hover:text-indigo-700">
                    ← Volver a grupos
                </a>
            </div>
        </section>

        <!-- Abrir materia -->
        <section v-if="puedeEditar" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-800">Abrir materias</h2>
            <p class="mt-1 text-sm text-slate-500">
                Abrir una materia es lo que la vuelve inscribible en este ciclo. Filtra por
                semestre y marca todas las que vayas a abrir.
            </p>

            <form class="mt-4 space-y-4" @submit.prevent="abrirMaterias">
                <div v-if="periodosDisponibles.length" class="sm:max-w-xs">
                    <CampoSelect
                        v-model="periodoFiltro"
                        etiqueta="Semestre / cuatrimestre"
                        :opciones="periodosDisponibles.map((p) => ({ valor: p, texto: `Periodo ${p}` }))"
                        vacio="Todos los periodos"
                        ayuda="Solo filtra la lista de abajo."
                    />
                </div>

                <CampoCasillas
                    v-model="formMateria.plan_materia_ids"
                    etiqueta="Materias del plan"
                    :opciones="materiasDelPeriodo.map((m) => ({
                        valor: m.id,
                        texto: `${m.clave_en_plan} · ${m.materia ?? ''}`,
                        ayuda: [m.periodo ? `periodo ${m.periodo}` : null, m.tipo].filter(Boolean).join(' · '),
                    }))"
                    :error="formMateria.errors.plan_materia_ids"
                    vacio="No hay materias disponibles en este periodo."
                />

                <button
                    type="submit"
                    :disabled="formMateria.processing || formMateria.plan_materia_ids.length === 0"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {{
                        formMateria.plan_materia_ids.length > 1
                            ? `Abrir ${formMateria.plan_materia_ids.length} materias`
                            : 'Abrir materia'
                    }}
                </button>
            </form>

            <p v-if="!materiasDisponibles.length" class="mt-2 text-xs text-amber-600">
                No hay materias disponibles: o ya están todas abiertas, o el plan no tiene malla cargada.
            </p>
        </section>

        <!-- Materias abiertas -->
        <section v-if="asignaturas.length" class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-100 px-6 py-3">
                <h2 class="text-base font-semibold text-slate-800">
                    Materias abiertas ({{ asignaturas.length }})
                </h2>
            </div>

            <ul class="divide-y divide-slate-100">
                <li v-for="asignatura in asignaturas" :key="asignatura.id" class="px-6 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-slate-800">
                                <span class="font-mono text-xs text-slate-500">{{ asignatura.clave_en_plan }}</span>
                                · {{ asignatura.materia }}
                            </p>
                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ asignatura.plan }} · {{ asignatura.inscritos }} inscrito(s)
                            </p>

                            <p class="mt-2 text-sm">
                                <span class="text-slate-500">Titular:</span>
                                <span v-if="asignatura.titular" class="ml-1 text-slate-800">
                                    {{ asignatura.titular }}
                                </span>
                                <span v-else class="ml-1 text-amber-600">
                                    sin asignar — nadie podría firmar el acta
                                </span>
                            </p>
                            <p v-if="asignatura.adjuntos.length" class="text-xs text-slate-500">
                                Adjuntos: {{ asignatura.adjuntos.join(', ') }}
                            </p>
                        </div>

                        <div v-if="puedeEditar" class="flex items-center gap-3">
                            <button
                                type="button"
                                class="text-sm text-indigo-600 hover:text-indigo-700"
                                @click="asignandoEn = asignandoEn === asignatura.id ? null : asignatura.id"
                            >
                                Asignar docente
                            </button>
                            <button
                                type="button"
                                class="text-sm text-slate-400 hover:text-red-600"
                                @click="quitarMateria(asignatura)"
                            >
                                Quitar
                            </button>
                        </div>
                    </div>

                    <form
                        v-if="asignandoEn === asignatura.id"
                        class="mt-3 flex flex-wrap items-end gap-3 rounded-lg bg-slate-50 p-3"
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
                        <button
                            type="submit"
                            :disabled="formDocente.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                        >
                            Asignar
                        </button>
                        <p v-if="!docentes.length" class="w-full text-xs text-amber-600">
                            No hay docentes registrados todavía.
                        </p>
                    </form>
                </li>
            </ul>
        </section>

        <p v-else class="rounded-xl bg-white px-4 py-12 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
            Este grupo no tiene materias abiertas.
        </p>
    </AppLayout>
</template>
