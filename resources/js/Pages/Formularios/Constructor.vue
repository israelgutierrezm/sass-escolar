<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Opcion {
    id: number;
    valor: string;
    etiqueta: string;
    orden: number;
}

interface Campo {
    id: number;
    pregunta: string;
    descripcion: string | null;
    tipo_campo_id: number;
    tipo: string | null;
    tipo_clave: string | null;
    obligatorio: boolean;
    orden: number;
    regex: string | null;
    mensaje_error: string | null;
    min: string | null;
    max: string | null;
    campo_padre_id: number | null;
    condicional: string | null;
    opciones: Opcion[];
}

const props = defineProps<{
    formulario: Record<string, any>;
    campos: Campo[];
    tiposCampo: { id: number; clave: string; nombre: string }[];
    asignaciones: {
        id: number;
        rol_id: number;
        rol: string;
        ambito_tipo: string | null;
        ambito: string | null;
        obligatorio: boolean;
    }[];
    /** Los recortes disponibles, por tipo. */
    destinos: Record<string, { id: number; nombre: string }[]>;
    /** `admite_ambito` dice si a ese rol se le puede acotar por programa académico. */
    roles: { id: number; nombre: string; admite_ambito: boolean }[];
    respuestas: number;
    congelado: boolean;
    puedeEditar: boolean;
}>();

const editable = computed(() => props.puedeEditar && !props.congelado);

/** Los tipos que sin opciones no significan nada. */
const TIPOS_CON_OPCIONES = ['select', 'multiselect', 'radio'];

function necesitaOpciones(tipoId: number | null): boolean {
    const tipo = props.tiposCampo.find((t) => t.id === tipoId);
    return tipo !== undefined && TIPOS_CON_OPCIONES.includes(tipo.clave);
}

/* --- Campos --- */
const agregando = ref(false);
const editandoCampo = ref<number | null>(null);

const formCampo = useForm({
    pregunta: '',
    descripcion: '',
    tipo_campo_id: props.tiposCampo[0]?.id ?? null,
    obligatorio: false,
    regex: '',
    mensaje_error: '',
    min: null as number | null,
    max: null as number | null,
    campo_padre_id: null as number | null,
    condicional: '',
});

function abrirNuevoCampo(): void {
    formCampo.reset();
    formCampo.tipo_campo_id = props.tiposCampo[0]?.id ?? null;
    agregando.value = true;
    editandoCampo.value = null;
}

function abrirEdicionCampo(campo: Campo): void {
    // El mismo botón alterna: si ya se está editando este campo, se pliega.
    if (editandoCampo.value === campo.id) {
        editandoCampo.value = null;

        return;
    }

    formCampo.pregunta = campo.pregunta;
    formCampo.descripcion = campo.descripcion ?? '';
    formCampo.tipo_campo_id = campo.tipo_campo_id;
    formCampo.obligatorio = campo.obligatorio;
    formCampo.regex = campo.regex ?? '';
    formCampo.mensaje_error = campo.mensaje_error ?? '';
    formCampo.min = campo.min === null ? null : Number(campo.min);
    formCampo.max = campo.max === null ? null : Number(campo.max);
    formCampo.campo_padre_id = campo.campo_padre_id;
    formCampo.condicional = campo.condicional ?? '';
    editandoCampo.value = campo.id;
    agregando.value = false;
}

function guardarCampo(): void {
    const base = `/formularios/${props.formulario.id}/campos`;

    if (editandoCampo.value !== null) {
        formCampo.put(`${base}/${editandoCampo.value}`, {
            preserveScroll: true,
            onSuccess: () => (editandoCampo.value = null),
        });

        return;
    }

    formCampo.post(base, {
        preserveScroll: true,
        onSuccess: () => { formCampo.reset(); agregando.value = false; },
    });
}

function eliminarCampo(campo: Campo): void {
    if (!confirm(`Eliminar "${campo.pregunta}"?`)) return;
    router.delete(`/formularios/${props.formulario.id}/campos/${campo.id}`, { preserveScroll: true });
}

function mover(campo: Campo, direccion: 'arriba' | 'abajo'): void {
    router.put(`/formularios/${props.formulario.id}/campos/${campo.id}/mover`, { direccion }, { preserveScroll: true });
}

/* --- Opciones --- */
const formOpcion = useForm({ etiqueta: '', valor: '' });
const agregandoOpcionEn = ref<number | null>(null);

function agregarOpcion(campoId: number): void {
    formOpcion.post(`/formularios/${props.formulario.id}/campos/${campoId}/opciones`, {
        preserveScroll: true,
        onSuccess: () => formOpcion.reset(),
    });
}

function eliminarOpcion(campoId: number, opcionId: number): void {
    router.delete(`/formularios/${props.formulario.id}/campos/${campoId}/opciones/${opcionId}`, { preserveScroll: true });
}

/* --- Asignaciones --- */

/*
 * Primero el ROL y sólo despues el recorte.
 *
 * Antes los cuatro destinos —rol, nivel, programa académico, oferta— se elegían como
 * hermanos, y eso decía algo que no es cierto: que un formulario asignado «a la
 * programa académico de Derecho» le llega a alguien. Nivel, programa académico y oferta no son
 * destinatarios, son recortes del destinatario.
 */
const formAsignacion = useForm({
    rol_id: null as number | null,
    ambito_tipo: null as string | null,
    ambito_id: null as number | null,
    obligatorio: false,
});

const rolElegido = computed(() => props.roles.find((r) => r.id === formAsignacion.rol_id) ?? null);

/**
 * El recorte sólo se ofrece a quien TIENE programa académico: aspirantes y alumnos.
 *
 * A un docente no se le puede acotar un formulario «a Derecho» porque no
 * pertenece a ningún programa académico; ofrecérselo era invitar a guardar una asignación
 * que después nadie sabría cómo resolver.
 */
const admiteAmbito = computed(() => rolElegido.value?.admite_ambito ?? false);

const opcionesAmbito = computed(() =>
    (props.destinos[formAsignacion.ambito_tipo ?? ''] ?? []).map((d) => ({ valor: d.id, texto: d.nombre })),
);

/** Cambiar de rol a uno sin programa académico limpia el recorte que hubiera puesto. */
function alCambiarRol(): void {
    if (! admiteAmbito.value) {
        formAsignacion.ambito_tipo = null;
        formAsignacion.ambito_id = null;
    }
}

function alCambiarTipoDeAmbito(): void {
    formAsignacion.ambito_id = null;
}

function asignar(): void {
    formAsignacion.post(`/formularios/${props.formulario.id}/asignaciones`, {
        preserveScroll: true,
        onSuccess: () => formAsignacion.reset(),
    });
}

function desasignar(id: number): void {
    router.delete(`/formularios/${props.formulario.id}/asignaciones/${id}`, { preserveScroll: true });
}

/* --- Versionar --- */
function versionar(): void {
    if (!confirm('Publicar una version nueva? La actual conserva sus respuestas y deja de usarse.')) return;
    router.post(`/formularios/${props.formulario.id}/versionar`);
}

/** Los candidatos a "campo del que depende": los demás campos de este formulario. */
const candidatosPadre = computed(() =>
    props.campos.filter((c) => c.id !== editandoCampo.value && c.opciones.length > 0),
);

const opcionesDelPadre = computed(() => {
    const padre = props.campos.find((c) => c.id === formCampo.campo_padre_id);
    return padre?.opciones ?? [];
});

/** El recorte, en minúscula: va dentro de una frase, no como encabezado. */
const etiquetaAmbito = (tipo: string | null) =>
    ({ nivel: 'nivel', programa_academico: 'programa_academico', oferta: 'oferta' })[tipo ?? ''] ?? tipo ?? '';
</script>

<template>
    <Head :title="formulario.titulo" />

    <AppLayout :titulo="formulario.titulo">
        <section class="tarjeta p-6">
            <BotonVolver href="/formularios" texto="Formularios" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ formulario.clave }} · v{{ formulario.version }}
                    </p>
                    <h2 class="text-lg font-semibold">{{ formulario.titulo }}</h2>
                    <p v-if="formulario.instruccion" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ formulario.instruccion }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Congelado: la regla que gobierna todo lo demás -->
        <div v-if="congelado" class="tarjeta border-l-4 border-amber-500 p-4 text-sm">
            <p class="font-medium text-amber-700">Este formulario está congelado.</p>
            <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                Ya tiene {{ respuestas }} respuestas capturadas. Cambiarlo reescribiría preguntas que
                alguien ya contestó, y el expediente diría algo que nadie dijo. Para modificarlo se
                publica una versión nueva: esta conserva sus respuestas y la nueva se usa de aquí en
                adelante.
            </p>
            <button
                v-if="puedeEditar"
                type="button"
                class="mt-3 rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="versionar"
            >
                Publicar versión {{ formulario.version + 1 }}
            </button>
        </div>

        <!-- Campos -->
        <section class="tarjeta overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <div>
                    <h2 class="text-base font-semibold">Preguntas ({{ campos.length }})</h2>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        En este orden las verá quien llene el formulario. Muévelas con las flechas.
                    </p>
                </div>
                <BotonAccion
                    v-if="editable && !agregando"
                    variante="nuevo"
                    texto="Agregar pregunta"
                    @click="abrirNuevoCampo"
                />
            </div>

            <ul v-if="campos.length">
                <li
                    v-for="(campo, i) in campos"
                    :key="campo.id"
                    class="border-t px-6 py-4"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 gap-3">
                            <!--
                                El número de orden, a la vista.
                                Sin él, «la tercera pregunta» había que contarla
                                con el dedo, y es justo como se habla de ellas al
                                revisar un formulario con alguien más.
                            -->
                            <span
                                class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold tabular-nums"
                                :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                            >{{ i + 1 }}</span>

                        <div class="min-w-0">
                            <p class="text-sm">
                                <span class="font-medium">{{ campo.pregunta }}</span>
                                <span v-if="campo.obligatorio" class="text-red-500" title="Obligatoria"> *</span>
                                <span
                                    class="ml-2 rounded-full px-2 py-0.5 text-xs"
                                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                >{{ campo.tipo }}</span>
                            </p>
                            <p v-if="campo.descripcion" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ campo.descripcion }}
                            </p>

                            <!-- Condicional: se lee como una frase -->
                            <p v-if="campo.campo_padre_id" class="mt-1 text-xs text-amber-700">
                                Se muestra solo si «{{ campos.find((c) => c.id === campo.campo_padre_id)?.pregunta }}»
                                = «{{ campo.condicional }}»
                            </p>

                            <p v-if="campo.regex" class="mt-1 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                /{{ campo.regex }}/
                            </p>
                            <p v-if="campo.min !== null || campo.max !== null" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                entre {{ campo.min ?? '−∞' }} y {{ campo.max ?? '∞' }}
                            </p>

                            <!-- Opciones -->
                            <div v-if="necesitaOpciones(campo.tipo_campo_id)" class="mt-2">
                                <p v-if="!campo.opciones.length" class="text-xs text-amber-600">
                                    Sin opciones: este campo no se puede contestar.
                                </p>
                                <ul v-else class="flex flex-wrap gap-1">
                                    <li
                                        v-for="opcion in campo.opciones"
                                        :key="opcion.id"
                                        class="flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs"
                                        style="background-color: color-mix(in srgb, currentColor 8%, transparent)"
                                    >
                                        {{ opcion.etiqueta }}
                                        <span class="font-mono opacity-60">{{ opcion.valor }}</span>
                                        <button
                                            v-if="editable"
                                            type="button"
                                            class="grid h-4 w-4 place-items-center rounded-full leading-none transition hover:bg-red-100 hover:text-red-700"
                                            title="Quitar opción"
                                            @click="eliminarOpcion(campo.id, opcion.id)"
                                        >×</button>
                                    </li>
                                </ul>

                                <div v-if="editable" class="mt-2">
                                    <div v-if="agregandoOpcionEn === campo.id" class="flex flex-wrap items-start gap-2">
                                        <div class="w-48">
                                            <CampoTexto v-model="formOpcion.etiqueta" etiqueta="Etiqueta" :error="formOpcion.errors.etiqueta" />
                                        </div>
                                        <div class="w-40">
                                            <CampoTexto v-model="formOpcion.valor" etiqueta="Valor" mono ayuda="Opcional." />
                                        </div>
                                        <BotonPrincipal
                                            class="alinea-con-campo"
                                            tipo="button"
                                            texto="Agregar"
                                            icono="crear"
                                            :deshabilitado="!formOpcion.etiqueta"
                                            @click="agregarOpcion(campo.id)"
                                        />
                                        <button
                                            type="button"
                                            class="rounded-lg border px-3 py-2 text-sm"
                                            :style="{ borderColor: 'var(--color-borde)' }"
                                            @click="agregandoOpcionEn = null"
                                        >Listo</button>
                                    </div>
                                    <button
                                        v-else
                                        type="button"
                                        class="text-xs"
                                        :style="{ color: 'var(--color-acento)' }"
                                        @click="agregandoOpcionEn = campo.id"
                                    >
                                        + opción
                                    </button>
                                </div>
                            </div>
                        </div>
                        </div>

                        <div v-if="editable" class="flex shrink-0 items-center gap-3 text-sm">
                            <!-- Con área de clic de verdad: eran dos triángulos
                                 de texto de ocho pixeles, imposibles de atinar. -->
                            <span class="flex flex-col gap-0.5">
                                <button
                                    type="button"
                                    :disabled="i === 0"
                                    class="grid h-5 w-6 place-items-center rounded text-xs transition hover:bg-fondo disabled:opacity-25 disabled:hover:bg-transparent"
                                    :style="{ color: 'var(--color-suave)' }"
                                    title="Subir"
                                    @click="mover(campo, 'arriba')"
                                >▲</button>
                                <button
                                    type="button"
                                    :disabled="i === campos.length - 1"
                                    class="grid h-5 w-6 place-items-center rounded text-xs transition hover:bg-fondo disabled:opacity-25 disabled:hover:bg-transparent"
                                    :style="{ color: 'var(--color-suave)' }"
                                    title="Bajar"
                                    @click="mover(campo, 'abajo')"
                                >▼</button>
                            </span>
                            <BotonAccion :variante="editandoCampo === campo.id ? 'cerrar' : 'editar'" @click="abrirEdicionCampo(campo)" />
                            <BotonAccion variante="eliminar" texto="Quitar" @click="eliminarCampo(campo)" />
                        </div>
                    </div>
                </li>
            </ul>

            <div v-else class="px-6 py-10 text-center">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no tiene preguntas, así que quien lo abra no verá nada que contestar.
                </p>
                <BotonAccion
                    v-if="editable && !agregando"
                    variante="nuevo"
                    texto="Agregar la primera"
                    class="mt-3"
                    @click="abrirNuevoCampo"
                />
            </div>

            <!-- Alta / edición de campo -->
            <form
                v-if="editable && (agregando || editandoCampo !== null)"
                class="border-t px-6 py-4"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="guardarCampo"
            >
                <h3 class="text-sm font-medium">{{ editandoCampo !== null ? 'Editar campo' : 'Nuevo campo' }}</h3>

                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <CampoTexto v-model="formCampo.pregunta" etiqueta="Pregunta" requerido :error="formCampo.errors.pregunta" />
                    <CampoSelect
                        v-model="formCampo.tipo_campo_id"
                        etiqueta="Tipo"
                        requerido
                        :opciones="tiposCampo.map((t) => ({ valor: t.id, texto: t.nombre }))"
                        :error="formCampo.errors.tipo_campo_id"
                    />
                    <CampoTexto v-model="formCampo.descripcion" etiqueta="Ayuda" :error="formCampo.errors.descripcion" />
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-4">
                    <CampoTexto v-model.number="formCampo.min" etiqueta="Mínimo" tipo="number" :error="formCampo.errors.min" />
                    <CampoTexto v-model.number="formCampo.max" etiqueta="Máximo" tipo="number" :error="formCampo.errors.max" />
                    <CampoTexto v-model="formCampo.regex" etiqueta="Patrón" mono :error="formCampo.errors.regex" ayuda="Expresión regular." />
                    <CampoTexto v-model="formCampo.mensaje_error" etiqueta="Mensaje de error" :error="formCampo.errors.mensaje_error" />
                </div>

                <!-- Condicional -->
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <CampoSelect
                        v-model="formCampo.campo_padre_id"
                        etiqueta="Se muestra solo si…"
                        :opciones="candidatosPadre.map((c) => ({ valor: c.id, texto: c.pregunta }))"
                        vacio="Siempre visible"
                        :error="formCampo.errors.campo_padre_id"
                        ayuda="Solo campos con opciones pueden condicionar a otro."
                    />
                    <CampoSelect
                        v-if="formCampo.campo_padre_id"
                        v-model="formCampo.condicional"
                        etiqueta="…tiene el valor"
                        :opciones="opcionesDelPadre.map((o) => ({ valor: o.valor, texto: o.etiqueta }))"
                        vacio="Elige el valor…"
                        :error="formCampo.errors.condicional"
                    />
                </div>

                <div class="mt-3">
                    <CampoCheckbox v-model="formCampo.obligatorio" etiqueta="Obligatorio" />
                </div>

                <div class="mt-4 flex gap-2">
                    <BotonPrincipal :procesando="formCampo.processing" texto="Guardar campo" />
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="agregando = false; editandoCampo = null"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </section>

        <!-- Asignaciones -->
        <section class="tarjeta overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">A quién se le muestra</h2>
                <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Sin asignaciones, este formulario no le aparece a nadie.
                </p>
            </div>

            <ul v-if="asignaciones.length">
                <li
                    v-for="a in asignaciones"
                    :key="a.id"
                    class="flex items-center justify-between gap-3 border-t px-6 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span>
                        {{ a.rol }}
                        <!-- El recorte va como píldora y no como texto seguido:
                             lo que se busca al revisar esta lista es si una
                             asignación está acotada o le llega a todo el rol. -->
                        <span
                            v-if="a.ambito"
                            class="ml-1.5 rounded-full px-2 py-0.5 text-xs"
                            :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                        >sólo {{ etiquetaAmbito(a.ambito_tipo) }}: {{ a.ambito }}</span>
                        <span v-else class="ml-1.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            (todos)
                        </span>
                    </span>
                    <button
                        v-if="puedeEditar"
                        type="button"
                        class="text-sm transition hover:text-red-600"
                        :style="{ color: 'var(--color-suave)' }"
                        @click="desasignar(a.id)"
                    >
                        Quitar
                    </button>
                </li>
            </ul>

            <p v-else class="px-6 py-6 text-center text-sm text-amber-600">
                Sin asignar: nadie lo verá.
            </p>

            <form v-if="puedeEditar" class="border-t px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="asignar">
                <div class="grid gap-3 sm:grid-cols-4">
                    <CampoSelect
                        v-model="formAsignacion.rol_id"
                        etiqueta="Rol"
                        requerido
                        vacio="Elige…"
                        :opciones="roles.map((r) => ({ valor: r.id, texto: r.nombre }))"
                        :error="formAsignacion.errors.rol_id"
                        @update:model-value="alCambiarRol"
                    />

                    <!--
                        Los subfiltros aparecen SOLO para aspirantes y alumnos.
                        Son los únicos con programa académico, y se acotan igual a propósito:
                        el aspirante se convierte en alumno y su expediente de
                        formularios viaja con él, así que si cada rol se recortara
                        distinto el expediente se partiría al cruzar.
                    -->
                    <template v-if="admiteAmbito">
                        <CampoSelect
                            v-model="formAsignacion.ambito_tipo"
                            etiqueta="Acotar a"
                            :opciones="[
                                { valor: null, texto: 'Todo el rol' },
                                { valor: 'nivel', texto: 'Un nivel de estudios' },
                                { valor: 'programa_academico', texto: 'Una programa_academico' },
                                { valor: 'oferta', texto: 'Una oferta' },
                            ]"
                            :error="formAsignacion.errors.ambito_tipo"
                            @update:model-value="alCambiarTipoDeAmbito"
                        />
                        <CampoSelect
                            v-if="formAsignacion.ambito_tipo"
                            v-model="formAsignacion.ambito_id"
                            etiqueta="Cuál"
                            requerido
                            vacio="Elige…"
                            :opciones="opcionesAmbito"
                            :error="formAsignacion.errors.ambito_id"
                        />
                    </template>

                    <div class="flex items-end">
                        <BotonPrincipal
                            :procesando="formAsignacion.processing"
                            :deshabilitado="!formAsignacion.rol_id"
                            texto="Asignar"
                            class="w-full"
                        />
                    </div>
                </div>

                <p v-if="rolElegido && !admiteAmbito" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    A {{ rolElegido.nombre.toLowerCase() }} se le muestra siempre: no pertenece a una
                    programa_academico, así que no hay por dónde acotarlo.
                </p>
            </form>
        </section>

        <!-- Versionar desde un formulario aún editable -->
        <section v-if="puedeEditar && !congelado" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Publicar una versión nueva</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Copia campos, opciones y asignaciones a una versión {{ formulario.version + 1 }}. Útil
                cuando quieres conservar la actual como referencia aunque todavía no tenga respuestas.
            </p>
            <button
                type="button"
                class="mt-3 rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @click="versionar"
            >
                Publicar versión {{ formulario.version + 1 }}
            </button>
        </section>
    </AppLayout>
</template>
