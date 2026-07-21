<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface MateriaAbierta {
    id: number;
    clave_en_plan: string | null;
    materia: string | null;
    plan: string | null;
    situacion: string | null;
    titular: string | null;
    adjuntos: string[];
    inscritos: number;
}

const props = defineProps<{
    grupo: Record<string, any>;
    asignaturas: MateriaAbierta[];
    materiasDisponibles: { id: number; etiqueta: string }[];
    docentes: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const formMateria = useForm({ plan_materia_id: null as number | null });
const formDocente = useForm({ persona_id: null as number | null, tipo: 'titular' });
const asignandoEn = ref<number | null>(null);

function abrirMateria(): void {
    formMateria.post(`/escolar/grupos/${props.grupo.id}/materias`, {
        preserveScroll: true,
        onSuccess: () => formMateria.reset(),
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
            <h2 class="text-base font-semibold text-slate-800">Abrir una materia</h2>
            <p class="mt-1 text-sm text-slate-500">
                Abrir una materia es lo que la vuelve inscribible en este ciclo.
            </p>

            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="abrirMateria">
                <div class="min-w-80 flex-1">
                    <CampoSelect
                        v-model="formMateria.plan_materia_id"
                        etiqueta="Materia del plan"
                        :opciones="materiasDisponibles.map((m) => ({ valor: m.id, texto: m.etiqueta }))"
                        vacio="Selecciona…"
                        :error="formMateria.errors.plan_materia_id"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="formMateria.processing || !materiasDisponibles.length"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    Abrir materia
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
                            <CampoSelect
                                v-model="formDocente.persona_id"
                                etiqueta="Docente"
                                :opciones="docentes.map((d) => ({ valor: d.id, texto: d.nombre }))"
                                vacio="Selecciona…"
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
