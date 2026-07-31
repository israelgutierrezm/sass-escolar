<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

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
    planes: { id: number; nombre: string; clave: string; carrera_id: number; total_periodos: number | null; unidad_periodo: string }[];
    ofertas: { carrera_id: number; plan_id: number; campus_id: number }[];
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

// Ofertas del campus elegido: lo que se cargó en «Oferta» para ese campus. Sin
// campus todavía no hay nada que ofrecer.
const ofertasDelCampus = computed(() =>
    form.campus_id === null ? [] : props.ofertas.filter((o) => o.campus_id === form.campus_id),
);

// Carreras ofrecidas: dentro de los niveles del ciclo Y con oferta abierta en el
// campus elegido. La oferta manda: si una carrera no está ofertada en ese
// campus, no puede abrirse un grupo suyo ahí.
const carrerasVisibles = computed(() => {
    const niveles = cicloElegido.value?.nivel_ids ?? [];
    const ofertadas = new Set(ofertasDelCampus.value.map((o) => o.carrera_id));

    return props.carreras.filter(
        (c) =>
            ofertadas.has(c.id) &&
            (niveles.length === 0 || (c.nivel_estudios_id !== null && niveles.includes(c.nivel_estudios_id))),
    );
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

// Planes ofrecidos en el campus elegido; si ya se eligió carrera, solo los suyos.
// También sale de la oferta: no se puede fijar un plan que no esté ofertado ahí.
const planesVisibles = computed(() => {
    const ofertados = new Set(
        ofertasDelCampus.value
            .filter((o) => carreraId.value === null || o.carrera_id === carreraId.value)
            .map((o) => o.plan_id),
    );

    return props.planes.filter((plan) => ofertados.has(plan.id));
});

// Plan elegido → cuántos periodos tiene, para ofrecer el select de periodo.
const planElegido = computed(() => props.planes.find((plan) => plan.id === form.plan_id) ?? null);

// El nombre real del periodo según el plan: «Semestre», «Cuatrimestre»,
// «Módulo», etc. Sin plan fijo se cae al genérico «Periodo».
const unidadPeriodo = computed(() => planElegido.value?.unidad_periodo ?? 'Periodo');

// Periodo 1..N según el plan, numerado con su nombre real: «Semestre 1»,
// «Cuatrimestre 1», etc. Sin plan fijo no se sabe el máximo, así que el campo
// queda deshabilitado.
const opcionesPeriodo = computed(() => {
    const total = planElegido.value?.total_periodos ?? 0;

    return Array.from({ length: total }, (_, i) => ({ valor: i + 1, texto: `${unidadPeriodo.value} ${i + 1}` }));
});

// Cambiar de carrera invalida el plan elegido si ya no pertenece a ella.
watch(carreraId, () => {
    const sigueSiendoValido = planesVisibles.value.some((plan) => plan.id === form.plan_id);

    if (!sigueSiendoValido) {
        form.plan_id = null;
    }
});

// Cambiar de campus rehace la oferta disponible: se limpian carrera y plan que
// ya no estén ofertados en el nuevo campus.
watch(
    () => form.campus_id,
    () => {
        if (carreraId.value && !carrerasVisibles.value.some((c) => c.id === carreraId.value)) {
            carreraId.value = null;
        }

        if (form.plan_id && !planesVisibles.value.some((p) => p.id === form.plan_id)) {
            form.plan_id = null;
        }
    },
);

// Cambiar de plan invalida el periodo si se sale del rango del nuevo plan.
watch(
    () => form.plan_id,
    () => {
        const total = planElegido.value?.total_periodos ?? 0;

        if (form.semestre && Number(form.semestre) > total) {
            form.semestre = null;
        }
    },
);

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

        <form class="space-y-6" @submit.prevent="enviar">
            <TarjetaSeccion titulo="Datos del grupo" descripcion="Ciclo, campus y plan que abre el grupo; el ciclo acota lo disponible." :icono="ICONOS.personas">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    :vacio="!form.campus_id ? 'Elige campus primero' : 'Todas las ofertadas'"
                    ayuda="Solo las carreras ofertadas en el campus. Filtra los planes de abajo; no se guarda en el grupo."
                />
                <CampoSelect
                    v-model="form.plan_id"
                    etiqueta="Plan de estudios"
                    :opciones="planesVisibles.map((p) => ({ valor: p.id, texto: `${p.clave} · ${p.nombre}` }))"
                    :vacio="!form.campus_id ? 'Elige campus primero' : (carreraId === null ? 'Sin plan fijo' : 'Sin plan fijo (de esta carrera)')"
                    :error="form.errors.plan_id"
                    ayuda="Solo los planes ofertados en el campus. Si lo fijas, solo se podrán abrir materias de ese plan."
                />
                <CampoSelect
                    v-model="form.semestre"
                    :etiqueta="`${unidadPeriodo} (opcional)`"
                    :opciones="opcionesPeriodo"
                    :vacio="planElegido ? `Sin ${unidadPeriodo.toLowerCase()} fijo` : 'Fija un plan primero'"
                    :error="form.errors.semestre"
                    :ayuda="planElegido
                        ? `Del 1 al ${planElegido.total_periodos ?? '—'}. Si lo pones, al abrir materias se preseleccionan las de ese ${unidadPeriodo.toLowerCase()}.`
                        : 'Fija un plan y se numerará según su tipo de periodo.'"
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
                    class="rounded-lg p-3 text-sm sm:col-span-2 lg:col-span-3"
                    style="background-color: color-mix(in srgb, #6366f1 8%, transparent)"
                >
                    {{ restriccionCiclo }}
                </p>
                </div>
                <template #pie>
                    <div class="flex items-center gap-3">
                        <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear grupo'" />
                        <a
                            href="/escolar/grupos"
                            class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido hover:bg-fondo"
                        >
                            Cancelar
                        </a>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
