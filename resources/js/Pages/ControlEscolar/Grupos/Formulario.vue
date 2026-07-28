<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Ciclo {
    id: number;
    nombre: string;
    campus_ids: number[];
    nivel_ids: number[];
}

const props = defineProps<{
    grupo: Record<string, any> | null;
    ciclos: Ciclo[];
    campus: { id: number; nombre: string }[];
    carreras: { id: number; nombre: string; nivel_estudios_id: number | null }[];
    planes: { id: number; nombre: string; clave: string; carrera_id: number }[];
    turnos: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.grupo !== null);

const form = useForm({
    ciclo_id: props.grupo?.ciclo_id ?? null,
    campus_id: props.grupo?.campus_id ?? null,
    plan_id: props.grupo?.plan_id ?? null,
    semestre: props.grupo?.semestre ?? null,
    clave: props.grupo?.clave ?? '',
    nombre: props.grupo?.nombre ?? '',
    cupo: props.grupo?.cupo ?? null,
    turno_id: props.grupo?.turno_id ?? null,
    situacion_id: props.grupo?.situacion_id ?? props.situaciones[0]?.id ?? null,
});

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

// El ciclo elegido acota el grupo: a sus campus y a su nivel de estudios.
const cicloElegido = computed(() => props.ciclos.find((c) => c.id === form.ciclo_id) ?? null);

// Campus ofrecidos: si el ciclo tiene campus, solo esos; si no, todos.
const campusVisibles = computed(() => {
    const ids = cicloElegido.value?.campus_ids ?? [];

    return ids.length ? props.campus.filter((c) => ids.includes(c.id)) : props.campus;
});

// Carreras ofrecidas: si el ciclo se acota a niveles, solo las de esos niveles.
const carrerasVisibles = computed(() => {
    const niveles = cicloElegido.value?.nivel_ids ?? [];

    return niveles.length
        ? props.carreras.filter((c) => c.nivel_estudios_id !== null && niveles.includes(c.nivel_estudios_id))
        : props.carreras;
});

const restriccionCiclo = computed(() => {
    if (!cicloElegido.value) {
        return null;
    }

    const partes: string[] = [];

    if (cicloElegido.value.campus_ids.length) {
        partes.push('a los campus del ciclo');
    }

    if (cicloElegido.value.nivel_ids.length) {
        partes.push('a las carreras de sus niveles de estudio');
    }

    return partes.length ? `Este ciclo acota el grupo ${partes.join(' y ')}.` : null;
});

/*
 * Carrera → plan, en cascada.
 *
 * El grupo solo guarda `plan_id`; la carrera es un filtro de la pantalla, no un
 * dato que se persista. Se ofrecía un único desplegable con TODOS los planes de
 * la escuela, donde "Plan 2026" de dos carreras distintas se ve idéntico y es
 * fácil atar el grupo a la carrera equivocada.
 *
 * Al editar, la carrera se deduce del plan que ya tiene guardado.
 */
const carreraId = ref<number | null>(
    props.planes.find((plan) => plan.id === props.grupo?.plan_id)?.carrera_id ?? null,
);

const planesDeLaCarrera = computed(() =>
    carreraId.value === null
        ? props.planes
        : props.planes.filter((plan) => plan.carrera_id === carreraId.value),
);

// Cambiar de carrera invalida el plan elegido si ya no pertenece a ella.
watch(carreraId, () => {
    const sigueSiendoValido = planesDeLaCarrera.value.some((plan) => plan.id === form.plan_id);

    if (!sigueSiendoValido) {
        form.plan_id = null;
    }
});

// Al cambiar de ciclo, lo que ya no cabe en su acotamiento se limpia: un campus
// que el nuevo ciclo no incluye, o una carrera/plan de otro nivel.
watch(
    () => form.ciclo_id,
    () => {
        if (form.campus_id && !campusVisibles.value.some((c) => c.id === form.campus_id)) {
            form.campus_id = null;
        }

        if (carreraId.value && !carrerasVisibles.value.some((c) => c.id === carreraId.value)) {
            carreraId.value = null;
        }
    },
);

function enviar(): void {
    esEdicion.value ? form.put(`/escolar/grupos/${props.grupo!.id}`) : form.post('/escolar/grupos');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar grupo' : 'Nuevo grupo'" />

    <AppLayout :titulo="esEdicion ? 'Editar grupo' : 'Nuevo grupo'">
        <NavEscolar />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="grid gap-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2">
                <CampoSelect
                    v-model="form.ciclo_id"
                    etiqueta="Ciclo"
                    requerido
                    :opciones="opciones(ciclos)"
                    vacio="Selecciona…"
                    :error="form.errors.ciclo_id"
                />
                <CampoSelect
                    v-model="form.campus_id"
                    etiqueta="Campus"
                    requerido
                    :opciones="opciones(campusVisibles)"
                    vacio="Selecciona…"
                    :error="form.errors.campus_id"
                />
                <CampoTexto v-model="form.clave" etiqueta="Clave" requerido mono :error="form.errors.clave" />
                <CampoTexto v-model="form.nombre" etiqueta="Nombre" :error="form.errors.nombre" />
                <CampoSelect
                    v-model="carreraId"
                    etiqueta="Carrera"
                    :opciones="opciones(carrerasVisibles)"
                    vacio="Todas las carreras"
                    ayuda="Filtra los planes de abajo. No se guarda en el grupo."
                />
                <CampoSelect
                    v-model="form.plan_id"
                    etiqueta="Plan de estudios"
                    :opciones="planesDeLaCarrera.map((p) => ({ valor: p.id, texto: `${p.clave} · ${p.nombre}` }))"
                    :vacio="carreraId === null ? 'Sin plan fijo' : 'Sin plan fijo (de esta carrera)'"
                    :error="form.errors.plan_id"
                    ayuda="Si lo fijas, solo se podrán abrir materias de ese plan."
                />
                <CampoTexto
                    v-model="form.semestre"
                    etiqueta="Semestre (opcional)"
                    tipo="number"
                    :error="form.errors.semestre"
                    ayuda="Si lo pones, al abrir materias se filtra por él por defecto."
                />
                <CampoSelect
                    v-model="form.turno_id"
                    etiqueta="Turno"
                    :opciones="opciones(turnos)"
                    vacio="Sin turno"
                    :error="form.errors.turno_id"
                />
                <CampoTexto
                    v-model="form.cupo"
                    etiqueta="Cupo"
                    tipo="number"
                    :error="form.errors.cupo"
                    ayuda="Se valida al inscribir."
                />
                <CampoSelect
                    v-model="form.situacion_id"
                    etiqueta="Situación"
                    requerido
                    :opciones="opciones(situaciones)"
                    :error="form.errors.situacion_id"
                />

                <p
                    v-if="restriccionCiclo"
                    class="sm:col-span-2 rounded-lg p-3 text-sm"
                    style="background-color: color-mix(in srgb, #6366f1 8%, transparent)"
                >
                    {{ restriccionCiclo }}
                </p>
            </section>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear grupo'" />
                <a
                    href="/escolar/grupos"
                    class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm text-slate-700 hover:bg-slate-50"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
