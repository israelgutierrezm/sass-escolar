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
    niveles: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.grupo !== null);

const form = useForm({
    ciclo_id: props.grupo?.ciclo_id ?? null,
    campus_id: props.grupo?.campus_id ?? null,
    nivel_estudios_id: props.grupo?.nivel_estudios_id ?? null,
    plan_id: props.grupo?.plan_id ?? null,
    semestre: props.grupo?.semestre ?? null,
    clave: props.grupo?.clave ?? '',
    nombre: props.grupo?.nombre ?? '',
    cupo: props.grupo?.cupo ?? 30,
    turno_id: props.grupo?.turno_id ?? null,
});

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

// El ciclo elegido acota el grupo: a sus campus y a su nivel de estudios.
const cicloElegido = computed(() => props.ciclos.find((c) => c.id === form.ciclo_id) ?? null);

/*
 * Ciclo y campus se acotan MUTUAMENTE, no en un solo sentido.
 *
 * Quien abre grupos no siempre razona igual: unos parten del ciclo que están
 * armando, otros del campus que administran. Si solo el ciclo filtrara al
 * campus, empezar por el campus dejaría el desplegable de ciclos ofreciendo
 * ciclos donde ese campus no existe, y el error saldría hasta guardar.
 */
const campusVisibles = computed(() => {
    const ids = cicloElegido.value?.campus_ids ?? [];

    return ids.length ? props.campus.filter((c) => ids.includes(c.id)) : props.campus;
});

const ciclosVisibles = computed(() => {
    if (form.campus_id === null) {
        return props.ciclos;
    }

    // Un ciclo sin campus declarados no está acotado: sirve para cualquiera.
    return props.ciclos.filter(
        (c) => c.campus_ids.length === 0 || c.campus_ids.includes(form.campus_id as number),
    );
});

// Ofertas del campus elegido: lo que se cargó en «Oferta» para ese campus. Sin
// campus todavía no hay nada que ofrecer.
const ofertasDelCampus = computed(() =>
    form.campus_id === null ? [] : props.ofertas.filter((o) => o.campus_id === form.campus_id),
);

// Niveles ofrecidos: los del ciclo si está acotado; si no, todos. El nivel es
// del GRUPO, no del plan: por eso se elige aquí y no se deduce después.
const nivelesVisibles = computed(() => {
    const ids = cicloElegido.value?.nivel_ids ?? [];

    return ids.length ? props.niveles.filter((n) => ids.includes(n.id)) : props.niveles;
});

// Carreras ofrecidas: las del NIVEL elegido y con oferta abierta en el campus.
// La oferta manda: si una carrera no está ofertada ahí, no puede abrirse un
// grupo suyo.
const carrerasVisibles = computed(() => {
    const ofertadas = new Set(ofertasDelCampus.value.map((o) => o.carrera_id));

    return props.carreras.filter(
        (c) =>
            ofertadas.has(c.id) &&
            (form.nivel_estudios_id === null || c.nivel_estudios_id === form.nivel_estudios_id),
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

/*
 * Grado 1..N, numerado con el nombre real del periodo del plan («Semestre 1»,
 * «Cuatrimestre 1»…).
 *
 * El grado es OBLIGATORIO y el plan no, así que no puede depender de él: sin
 * plan fijo se ofrece un rango genérico de 1 a 12, suficiente para cualquier
 * nivel. Con plan, el máximo lo pone el plan.
 */
const opcionesPeriodo = computed(() => {
    const total = planElegido.value?.total_periodos ?? 12;

    return Array.from({ length: total }, (_, i) => ({ valor: i + 1, texto: `${unidadPeriodo.value} ${i + 1}` }));
});

/*
 * Cada paso se muestra cuando el anterior ya está resuelto.
 *
 * Los seis campos se ofrecían de golpe, la mitad deshabilitados con un «Elige
 * campus primero» dentro del desplegable: hay que abrirlos para enterarse de
 * que no había nada que elegir. Apareciendo conforme se avanza, el orden de
 * captura se ve sin tener que descubrirlo.
 */
const hayOrigen = computed(() => form.ciclo_id !== null && form.campus_id !== null);
const muestraCarrera = computed(() => hayOrigen.value && form.nivel_estudios_id !== null);
const muestraPlan = computed(() => muestraCarrera.value && carreraId.value !== null);
const muestraGrado = computed(() => muestraPlan.value && form.plan_id !== null);

/*
 * Callejones sin salida de la oferta.
 *
 * Todo lo que se ofrece aquí sale de lo cargado en «Oferta»: si ahí no está la
 * combinación, el desplegable siguiente aparece vacío y no hay forma de saber
 * por qué desde esta pantalla. Se dice cuál es el hueco y dónde se llena.
 */
const nivelSinCarreras = computed(() => muestraCarrera.value && carrerasVisibles.value.length === 0);
const carreraSinPlanes = computed(() => muestraPlan.value && planesVisibles.value.length === 0);

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
        // El acotamiento es mutuo: si el ciclo elegido no incluye este campus,
        // el que sobra es el ciclo, porque el campus es lo que se acaba de tocar.
        if (form.ciclo_id && !ciclosVisibles.value.some((c) => c.id === form.ciclo_id)) {
            form.ciclo_id = null;
        }

        if (carreraId.value && !carrerasVisibles.value.some((c) => c.id === carreraId.value)) {
            carreraId.value = null;
        }

        if (form.plan_id && !planesVisibles.value.some((p) => p.id === form.plan_id)) {
            form.plan_id = null;
        }
    },
);

// Cambiar de plan invalida el grado: sin plan no hay grado que capturar, y con
// otro plan el número puede salirse de su rango.
watch(
    () => form.plan_id,
    () => {
        if (form.plan_id === null) {
            form.semestre = null;

            return;
        }

        const total = planElegido.value?.total_periodos ?? 12;

        if (form.semestre && Number(form.semestre) > total) {
            form.semestre = null;
        }
    },
);

// Cambiar de nivel rehace las carreras: se limpia lo que ya no corresponde.
watch(
    () => form.nivel_estudios_id,
    () => {
        if (carreraId.value && !carrerasVisibles.value.some((c) => c.id === carreraId.value)) {
            carreraId.value = null;
            form.plan_id = null;
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
            <!-- Cómo se llama el grupo: se escribe de corrido, sin depender de
                 nada, así que va primero y no estorba la cascada de abajo. -->
            <TarjetaSeccion
                titulo="Identificación"
                descripcion="Cómo se le va a llamar a este grupo en listas y actas."
                :icono="ICONOS.personas"
            >
                <div class="grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.clave" etiqueta="Clave" requerido mono :error="form.errors.clave" />
                    <CampoTexto
                        v-model="form.nombre"
                        etiqueta="Nombre"
                        :error="form.errors.nombre"
                        ayuda="Opcional. Si lo dejas vacío se identifica por la clave."
                        class="sm:col-span-2"
                    />
                </div>
            </TarjetaSeccion>

            <!--
                Cascada. Cada campo acota al siguiente y se muestra bloqueado
                mientras le falte su antecedente: así el orden de captura se ve,
                en vez de tener que descubrirlo abriendo desplegables vacíos.

                Ciclo y campus son intercambiables a propósito (ver el computed
                `ciclosVisibles`): se puede empezar por cualquiera de los dos.
            -->
            <TarjetaSeccion
                titulo="Ciclo, campus y plan"
                descripcion="Empieza por ciclo o por campus, el que tengas más a mano; de ahí en adelante cada paso aparece cuando el anterior está resuelto."
                :icono="ICONOS.calendario"
            >
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoSelect
                        v-model="form.ciclo_id"
                        etiqueta="1 · Ciclo"
                        requerido
                        :opciones="opciones(ciclosVisibles)"
                        vacio="Selecciona…"
                        :error="form.errors.ciclo_id"
                        :ayuda="form.campus_id ? 'Solo los ciclos que incluyen ese campus.' : 'Puedes empezar por aquí o por el campus.'"
                    />
                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="1 · Campus"
                        requerido
                        :opciones="opciones(campusVisibles)"
                        vacio="Selecciona…"
                        :error="form.errors.campus_id"
                        :ayuda="form.ciclo_id ? 'Solo los campus del ciclo.' : 'Puedes empezar por aquí o por el ciclo.'"
                    />
                    <!-- Del nivel en adelante, cada paso aparece cuando el
                         anterior está resuelto. -->
                    <CampoSelect
                        v-if="hayOrigen"
                        v-model="form.nivel_estudios_id"
                        etiqueta="2 · Nivel de estudios"
                        requerido
                        :opciones="opciones(nivelesVisibles)"
                        vacio="Selecciona…"
                        :error="form.errors.nivel_estudios_id"
                        ayuda="Todo grupo pertenece a un nivel. Filtra las carreras."
                    />
                    <CampoSelect
                        v-if="muestraCarrera"
                        v-model="carreraId"
                        etiqueta="3 · Carrera"
                        requerido
                        :opciones="opciones(carrerasVisibles)"
                        vacio="Selecciona…"
                        ayuda="Solo las ofertadas en ese campus. Filtra los planes; no se guarda en el grupo."
                    />
                    <CampoSelect
                        v-if="muestraPlan"
                        v-model="form.plan_id"
                        etiqueta="4 · Plan de estudios"
                        requerido
                        :opciones="planesVisibles.map((p) => ({ valor: p.id, texto: `${p.clave} · ${p.nombre}` }))"
                        vacio="Selecciona…"
                        :error="form.errors.plan_id"
                        ayuda="Solo se podrán abrir materias de este plan."
                    />
                    <CampoSelect
                        v-if="muestraGrado"
                        v-model="form.semestre"
                        :etiqueta="`5 · ${unidadPeriodo} (grado)`"
                        requerido
                        :opciones="opcionesPeriodo"
                        vacio="Selecciona…"
                        :error="form.errors.semestre"
                        :ayuda="`Del 1 al ${planElegido?.total_periodos ?? '—'}. No cambia al abrirle materias de otro grado.`"
                    />

                    <!--
                        Los huecos de la oferta, dichos donde aparecen.
                        Sin esto el desplegable siguiente sale vacío y no hay
                        manera de saber desde aquí que lo que falta se carga en
                        otra pantalla.
                    -->
                    <p
                        v-if="nivelSinCarreras"
                        class="rounded-lg border-l-4 border-l-amber-500 p-3 text-sm sm:col-span-2 lg:col-span-3"
                        style="background-color: color-mix(in srgb, #f59e0b 8%, transparent)"
                    >
                        Ese nivel no tiene ninguna carrera ofertada en este campus. Cárgala en
                        <!-- En otra pestaña a propósito: lo capturado hasta aquí
                             se conserva para poder seguir al volver. -->
                        <a href="/academico/ofertas" target="_blank" rel="noopener" class="underline">Oferta</a>
                        y vuelve.
                    </p>

                    <p
                        v-else-if="carreraSinPlanes"
                        class="rounded-lg border-l-4 border-l-amber-500 p-3 text-sm sm:col-span-2 lg:col-span-3"
                        style="background-color: color-mix(in srgb, #f59e0b 8%, transparent)"
                    >
                        Esa carrera no tiene ningún plan de estudios ofertado en este campus. Cárgalo
                        en <a href="/academico/ofertas" target="_blank" rel="noopener" class="underline">Oferta</a>
                        y vuelve.
                    </p>

                    <p
                        v-if="restriccionCiclo"
                        class="rounded-lg p-3 text-sm sm:col-span-2 lg:col-span-3"
                        style="background-color: color-mix(in srgb, #6366f1 8%, transparent)"
                    >
                        {{ restriccionCiclo }}
                    </p>
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion
                titulo="Capacidad"
                descripcion="Cuántos caben y en qué turno."
                :icono="ICONOS.ajustes"
            >
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoTexto
                        v-model="form.cupo"
                        etiqueta="Cupo"
                        tipo="number"
                        min="1"
                        requerido
                        :error="form.errors.cupo"
                        ayuda="Se valida al inscribir."
                    />
                    <CampoSelect
                        v-model="form.turno_id"
                        etiqueta="Turno"
                        :opciones="opciones(turnos)"
                        vacio="Sin turno"
                        :error="form.errors.turno_id"
                        ayuda="Opcional."
                    />
                </div>

                <template #pie>
                    <div class="flex flex-wrap items-center gap-3">
                        <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear grupo'" />
                        <a
                            href="/escolar/grupos"
                            class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido hover:bg-fondo"
                        >
                            Cancelar
                        </a>
                        <!-- La situación ya no se captura: preguntarla al alta era
                             ofrecer un estado que el grupo todavía no puede tener. -->
                        <span v-if="!esEdicion" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                            Al guardar pasarás a abrir las materias.
                        </span>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
