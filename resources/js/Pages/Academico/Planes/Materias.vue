<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

interface Materia {
    id: number;
    asignatura_id: number;
    asignatura: string | null;
    asignatura_clave: string | null;
    clave_en_plan: string;
    periodo: number | null;
    tipo: string;
    creditos: number | null;
    creditos_sobreescritos: boolean;
}

const props = defineProps<{
    plan: {
        id: number;
        nombre: string;
        clave: string;
        carrera: string | null;
        total_periodos: number | null;
        total_creditos: number;
        minimo_creditos: number;
    };
    materias: Materia[];
    creditosCargados: number;
    tiposAsignatura: { id: number; nombre: string }[];
    clasificaciones: { id: number; nombre: string }[];
    areas: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const editando = ref<number | null>(null);
const editandoNombre = ref<string | null>(null);
const mostrarAlta = ref(false);

const opciones = (lista: { id: number; nombre: string }[]) => lista.map((x) => ({ valor: x.id, texto: x.nombre }));

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
    // Ubicación en el plan
    clave_en_plan: '',
    periodo: null as number | null,
    tipo: 'obligatoria',
    creditos_en_plan: null as number | null,
});

/**
 * Malla agrupada: las obligatorias y de tronco común van por periodo; TODAS las
 * optativas se juntan en su propio bloque al final (no pertenecen a un periodo
 * fijo, el alumno las elige). Así se leen ordenadas y no se mezclan.
 */
const grupos = computed(() => {
    const porPeriodo = new Map<number | null, Materia[]>();
    const optativas: Materia[] = [];

    for (const materia of props.materias) {
        if (materia.tipo === 'optativa') {
            optativas.push(materia);
            continue;
        }
        const clave = materia.periodo ?? null;
        porPeriodo.set(clave, [...(porPeriodo.get(clave) ?? []), materia]);
    }

    const ordenados = [...porPeriodo.entries()]
        .sort((a, b) => {
            if (a[0] === null) return 1;
            if (b[0] === null) return -1;
            return a[0] - b[0];
        })
        .map(([periodo, lista]) => ({
            clave: periodo === null ? 'sin-periodo' : `periodo-${periodo}`,
            titulo: periodo === null ? 'Sin periodo asignado' : `Periodo ${periodo}`,
            optativa: false,
            lista,
        }));

    if (optativas.length) {
        ordenados.push({ clave: 'optativas', titulo: 'Optativas', optativa: true, lista: optativas });
    }

    return ordenados;
});

const opcionesTipo = [
    { valor: 'obligatoria', texto: 'Obligatoria' },
    { valor: 'optativa', texto: 'Optativa' },
    { valor: 'tronco_comun', texto: 'Tronco común' },
];

/** Diferencia entre lo cargado y lo que el plan declara: ayuda a cuadrar la malla. */
const diferenciaCreditos = computed(() => props.creditosCargados - props.plan.total_creditos);

function abrirAlta(): void {
    mostrarAlta.value = true;
    editando.value = null;
    form.reset();
    form.clearErrors();
}

function abrirEdicion(materia: Materia): void {
    mostrarAlta.value = false;
    editando.value = materia.id;
    editandoNombre.value = materia.asignatura;
    form.clearErrors();
    // Al editar solo se toca la ubicación en el plan.
    form.clave_en_plan = materia.clave_en_plan;
    form.periodo = materia.periodo;
    form.tipo = materia.tipo;
    form.creditos_en_plan = materia.creditos_sobreescritos ? materia.creditos : null;
}

function guardar(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            mostrarAlta.value = false;
            editando.value = null;
            form.reset();
        },
    };

    editando.value !== null
        ? form.put(`/academico/planes/${props.plan.id}/materias/${editando.value}`, opciones)
        : form.post(`/academico/planes/${props.plan.id}/materias`, opciones);
}

function quitar(materia: Materia): void {
    if (!confirm(`¿Quitar "${materia.asignatura}" del plan?`)) {
        return;
    }

    router.delete(`/academico/planes/${props.plan.id}/materias/${materia.id}`, { preserveScroll: true });
}

const etiquetaTipo = (tipo: string) => opcionesTipo.find((o) => o.valor === tipo)?.texto ?? tipo;
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
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Declarados en el plan</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-800">{{ plan.total_creditos }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Diferencia</dt>
                    <dd
                        class="mt-0.5 text-lg font-semibold"
                        :class="diferenciaCreditos === 0 ? 'text-emerald-600' : 'text-amber-600'"
                    >
                        {{ diferenciaCreditos > 0 ? '+' : '' }}{{ diferenciaCreditos }}
                    </dd>
                </div>
            </dl>

            <p v-if="diferenciaCreditos !== 0 && materias.length" class="mt-3 text-xs text-amber-600">
                Los créditos cargados no cuadran con los declarados en el plan. Revisa la malla o ajusta el
                total del plan.
            </p>
        </section>

        <!-- Alta -->
        <section v-if="puedeEditar" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-slate-800">
                        {{ editando !== null ? 'Editar materia del plan' : 'Agregar materia' }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        La clave del plan es la que aparecerá en el acta de calificaciones.
                    </p>
                </div>
                <button
                    v-if="!mostrarAlta && editando === null"
                    type="button"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    @click="abrirAlta"
                >
                    Agregar materia
                </button>
            </div>

            <form
                v-if="mostrarAlta || editando !== null"
                class="mt-5 grid gap-4 sm:grid-cols-6"
                @submit.prevent="guardar"
            >
                <!-- ALTA: se crea la asignatura aquí mismo. -->
                <template v-if="editando === null">
                    <p class="sm:col-span-6 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        Datos de la asignatura
                    </p>
                    <div class="sm:col-span-3">
                        <CampoTexto v-model="form.nombre" etiqueta="Nombre de la asignatura" requerido :error="form.errors.nombre" />
                    </div>
                    <CampoTexto v-model="form.clave" etiqueta="Clave" requerido mono :error="form.errors.clave" />
                    <CampoTexto v-model="form.identificador" etiqueta="Identificador" requerido :error="form.errors.identificador" />
                    <CampoTexto v-model="form.creditos" etiqueta="Créditos" tipo="number" requerido :error="form.errors.creditos" />

                    <CampoSelect
                        v-model="form.tipo_asignatura_id"
                        etiqueta="Tipo de asignatura"
                        requerido
                        :opciones="opciones(tiposAsignatura)"
                        vacio="Selecciona…"
                        :error="form.errors.tipo_asignatura_id"
                    />
                    <CampoSelect v-model="form.clasificacion_id" etiqueta="Clasificación" :opciones="opciones(clasificaciones)" vacio="Sin especificar" :error="form.errors.clasificacion_id" />
                    <CampoSelect v-model="form.area_id" etiqueta="Área" :opciones="opciones(areas)" vacio="Sin especificar" :error="form.errors.area_id" />
                    <CampoTexto v-model="form.horas_teoria" etiqueta="Horas teoría" tipo="number" :error="form.errors.horas_teoria" />
                    <CampoTexto v-model="form.horas_practica" etiqueta="Horas práctica" tipo="number" :error="form.errors.horas_practica" />
                </template>

                <!-- EDICIÓN: la asignatura ya existe; solo se ajusta su lugar. -->
                <div v-else class="sm:col-span-6">
                    <span class="text-sm" :style="{ color: 'var(--color-suave)' }">Editando la ubicación de</span>
                    <p class="font-medium">{{ editandoNombre }}</p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">La asignatura en sí se edita en «Asignaturas».</p>
                </div>

                <!-- Ubicación en el plan (para alta y edición). -->
                <div class="sm:col-span-6 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Ubicación en el plan</p>
                    <div class="grid gap-4 sm:grid-cols-6">
                        <CampoTexto
                            v-model="form.periodo"
                            etiqueta="Periodo"
                            tipo="number"
                            :error="form.errors.periodo"
                            ayuda="Vacío = sin periodo fijo (p. ej. optativas)."
                        />
                        <CampoSelect
                            v-model="form.tipo"
                            etiqueta="Tipo en el plan"
                            requerido
                            :opciones="opcionesTipo"
                            :error="form.errors.tipo"
                        />
                        <CampoTexto
                            v-model="form.clave_en_plan"
                            etiqueta="Clave de acta"
                            mono
                            :error="form.errors.clave_en_plan"
                            :ayuda="editando === null ? 'Vacío = usa la clave de la asignatura.' : ''"
                        />
                        <div class="sm:col-span-3">
                            <CampoTexto
                                v-model="form.creditos_en_plan"
                                etiqueta="Créditos en este plan"
                                tipo="number"
                                :error="form.errors.creditos_en_plan"
                                ayuda="Déjalo vacío para usar los de la asignatura."
                            />
                        </div>
                    </div>
                </div>

                <div class="flex items-end gap-2 sm:col-span-6">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                    >
                        {{ form.processing ? 'Guardando…' : editando !== null ? 'Guardar' : 'Agregar' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                        @click="
                            mostrarAlta = false;
                            editando = null;
                            form.reset();
                        "
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <!-- Malla agrupada (periodos + un bloque de Optativas) -->
        <section v-if="materias.length" class="space-y-4">
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
                        <svg v-if="grupo.optativa" class="h-4 w-4" :style="{ color: 'var(--color-acento)' }" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                        {{ grupo.titulo }}
                    </h3>
                    <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ grupo.lista.length }} materia(s) ·
                        {{ grupo.lista.reduce((suma, m) => suma + (m.creditos ?? 0), 0) }} créditos
                    </span>
                </div>

                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="materia in grupo.lista" :key="materia.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ materia.clave_en_plan }}</td>
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
                                <span v-if="materia.creditos_sobreescritos" class="text-xs text-amber-600">(ajustado)</span>
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
                    </tbody>
                </table>
            </div>
        </section>

        <p v-else class="rounded-xl bg-white px-4 py-12 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
            Este plan aún no tiene materias. Agrégalas desde el catálogo de asignaturas.
        </p>
    </AppLayout>
</template>
