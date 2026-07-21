<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Requisito {
    id: number;
    tipo: string;
    minimo_creditos: number | null;
    requiere: { clave_en_plan: string; nombre: string | null } | null;
}

interface Componente {
    id: number;
    componente: string;
    parcial: number | null;
    porcentaje: number;
    orden: number;
}

const props = defineProps<{
    plan: { id: number; nombre: string; carrera: string | null };
    materia: {
        id: number;
        clave_en_plan: string;
        asignatura: string | null;
        periodo: number | null;
        tipo: string;
        creditos: number | null;
    };
    seriacion: Requisito[];
    componentes: Componente[];
    sumaPorcentajes: number;
    candidatas: { id: number; etiqueta: string }[];
    puedeEditar: boolean;
}>();

const base = computed(() => `/academico/planes/${props.plan.id}/materias/${props.materia.id}`);

// --- Seriación ---
const formRequisito = useForm({
    requiere_plan_materia_id: null as number | null,
    tipo: 'aprobada',
    minimo_creditos: null as number | null,
});

const porCreditos = ref(false);

function agregarRequisito(): void {
    // Un requisito es o una materia o un mínimo de créditos, nunca ambos.
    if (porCreditos.value) {
        formRequisito.requiere_plan_materia_id = null;
    } else {
        formRequisito.minimo_creditos = null;
    }

    formRequisito.post(`${base.value}/seriacion`, {
        preserveScroll: true,
        onSuccess: () => formRequisito.reset(),
    });
}

function quitarRequisito(id: number): void {
    router.delete(`${base.value}/seriacion/${id}`, { preserveScroll: true });
}

// --- Esquema de evaluación ---
const formComponente = useForm({
    componente: '',
    parcial: null as number | null,
    porcentaje: null as number | null,
});

const editandoComponente = ref<number | null>(null);

const restante = computed(() => Math.round((100 - props.sumaPorcentajes) * 100) / 100);
const esquemaCompleto = computed(() => Math.abs(props.sumaPorcentajes - 100) < 0.01);

function guardarComponente(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            formComponente.reset();
            editandoComponente.value = null;
        },
    };

    editandoComponente.value !== null
        ? formComponente.put(`${base.value}/evaluacion/${editandoComponente.value}`, opciones)
        : formComponente.post(`${base.value}/evaluacion`, opciones);
}

function editarComponente(componente: Componente): void {
    editandoComponente.value = componente.id;
    formComponente.componente = componente.componente;
    formComponente.parcial = componente.parcial;
    formComponente.porcentaje = componente.porcentaje;
    formComponente.clearErrors();
}

function quitarComponente(id: number): void {
    router.delete(`${base.value}/evaluacion/${id}`, { preserveScroll: true });
}

const etiquetaTipo = (tipo: string) => (tipo === 'aprobada' ? 'Aprobada' : 'Cursada');
</script>

<template>
    <Head :title="`${materia.clave_en_plan} · ${plan.nombre}`" />

    <AppLayout titulo="Materia del plan">
        <NavAcademico />

        <!-- Cabecera -->
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm text-slate-500">{{ materia.clave_en_plan }}</p>
                    <h2 class="text-lg font-semibold text-slate-800">{{ materia.asignatura }}</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ plan.nombre }} · {{ plan.carrera }}
                        <span v-if="materia.periodo"> · Periodo {{ materia.periodo }}</span>
                        · {{ materia.creditos }} créditos
                    </p>
                </div>
                <a
                    :href="`/academico/planes/${plan.id}/materias`"
                    class="text-sm text-indigo-600 hover:text-indigo-700"
                >
                    ← Volver a la malla
                </a>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Seriación -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-base font-semibold text-slate-800">Requisitos para cursarla</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Se validan al inscribir: la materia no se puede tomar sin cumplirlos.
                </p>

                <ul v-if="seriacion.length" class="mt-4 space-y-2">
                    <li
                        v-for="requisito in seriacion"
                        :key="requisito.id"
                        class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2"
                    >
                        <div class="text-sm">
                            <template v-if="requisito.requiere">
                                <span class="font-mono text-xs text-slate-500">
                                    {{ requisito.requiere.clave_en_plan }}
                                </span>
                                <span class="ml-1 text-slate-800">{{ requisito.requiere.nombre }}</span>
                                <span class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                                    {{ etiquetaTipo(requisito.tipo) }}
                                </span>
                            </template>
                            <template v-else>
                                <span class="text-slate-800">
                                    Mínimo {{ requisito.minimo_creditos }} créditos acumulados
                                </span>
                            </template>
                        </div>
                        <button
                            v-if="puedeEditar"
                            type="button"
                            class="text-xs text-slate-400 hover:text-red-600"
                            @click="quitarRequisito(requisito.id)"
                        >
                            Quitar
                        </button>
                    </li>
                </ul>
                <p v-else class="mt-4 rounded-lg bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">
                    Sin requisitos: se puede cursar desde el inicio.
                </p>

                <form v-if="puedeEditar" class="mt-5 space-y-3 border-t border-slate-100 pt-4" @submit.prevent="agregarRequisito">
                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-1.5">
                            <input v-model="porCreditos" type="radio" :value="false" class="text-indigo-600" />
                            Otra materia
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input v-model="porCreditos" type="radio" :value="true" class="text-indigo-600" />
                            Mínimo de créditos
                        </label>
                    </div>

                    <template v-if="!porCreditos">
                        <CampoSelect
                            v-model="formRequisito.requiere_plan_materia_id"
                            etiqueta="Materia requisito"
                            :opciones="candidatas.map((c) => ({ valor: c.id, texto: c.etiqueta }))"
                            vacio="Selecciona…"
                            :error="formRequisito.errors.requiere_plan_materia_id"
                        />
                        <CampoSelect
                            v-model="formRequisito.tipo"
                            etiqueta="Debe estar"
                            :opciones="[
                                { valor: 'aprobada', texto: 'Aprobada' },
                                { valor: 'cursada', texto: 'Cursada (basta con haberla llevado)' },
                            ]"
                            :error="formRequisito.errors.tipo"
                        />
                    </template>
                    <CampoTexto
                        v-else
                        v-model="formRequisito.minimo_creditos"
                        etiqueta="Créditos mínimos acumulados"
                        tipo="number"
                        :error="formRequisito.errors.minimo_creditos"
                    />

                    <button
                        type="submit"
                        :disabled="formRequisito.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                        Agregar requisito
                    </button>
                </form>
            </section>

            <!-- Esquema de evaluación -->
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-800">Composición de la calificación</h2>
                        <p class="mt-1 text-sm text-slate-500">Los porcentajes deben sumar 100%.</p>
                    </div>
                    <span
                        class="rounded-full px-3 py-1 text-sm font-medium"
                        :class="esquemaCompleto ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                    >
                        {{ sumaPorcentajes }}%
                    </span>
                </div>

                <ul v-if="componentes.length" class="mt-4 space-y-2">
                    <li
                        v-for="componente in componentes"
                        :key="componente.id"
                        class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2"
                    >
                        <div class="text-sm">
                            <span class="text-slate-800">{{ componente.componente }}</span>
                            <span v-if="componente.parcial" class="ml-2 text-xs text-slate-400">
                                parcial {{ componente.parcial }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-medium text-slate-700">{{ componente.porcentaje }}%</span>
                            <template v-if="puedeEditar">
                                <button
                                    type="button"
                                    class="text-xs text-indigo-600 hover:text-indigo-700"
                                    @click="editarComponente(componente)"
                                >
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    class="text-xs text-slate-400 hover:text-red-600"
                                    @click="quitarComponente(componente.id)"
                                >
                                    Quitar
                                </button>
                            </template>
                        </div>
                    </li>
                </ul>
                <p v-else class="mt-4 rounded-lg bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">
                    Sin esquema definido. La calificación final no podrá calcularse.
                </p>

                <p v-if="componentes.length && !esquemaCompleto" class="mt-3 text-xs text-amber-600">
                    Faltan {{ restante }}% por asignar.
                </p>

                <form v-if="puedeEditar" class="mt-5 space-y-3 border-t border-slate-100 pt-4" @submit.prevent="guardarComponente">
                    <CampoTexto
                        v-model="formComponente.componente"
                        etiqueta="Componente"
                        requerido
                        marcador="parcial_1, final, lms…"
                        :error="formComponente.errors.componente"
                    />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <CampoTexto
                            v-model="formComponente.parcial"
                            etiqueta="Parcial"
                            tipo="number"
                            :error="formComponente.errors.parcial"
                            ayuda="Opcional."
                        />
                        <CampoTexto
                            v-model="formComponente.porcentaje"
                            etiqueta="Porcentaje"
                            tipo="number"
                            requerido
                            :error="formComponente.errors.porcentaje"
                        />
                    </div>

                    <div class="flex gap-2">
                        <button
                            type="submit"
                            :disabled="formComponente.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                        >
                            {{ editandoComponente !== null ? 'Guardar' : 'Agregar componente' }}
                        </button>
                        <button
                            v-if="editandoComponente !== null"
                            type="button"
                            class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                            @click="
                                editandoComponente = null;
                                formComponente.reset();
                            "
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
