<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';
import { ICONOS } from '@/iconos';

/**
 * Mi expediente, desde el lado del alumno.
 *
 * Misma forma que el del docente y por la misma razón: lo primero es QUÉ FALTA.
 * Nadie entra aquí a mirar lo que ya entregó; se entra porque control escolar
 * pidió un papel, o porque venció el comprobante de domicilio. Si no hay nada
 * que hacer, la tarjeta de pendientes no aparece: un tablero que siempre dice
 * algo deja de leerse.
 */
interface Documento {
    id: number;
    documento_id: number | null;
    documento: string | null;
    descripcion: string | null;
    estado: string | null;
    estado_clave: string | null;
    vigencia: string | null;
    vencido: boolean;
    observaciones: string | null;
}

interface TipoDocumento {
    id: number;
    nombre: string;
    obligatorio: boolean;
}

const props = defineProps<{
    persona: Record<string, any>;
    inscripciones: { matricula: string | null; carrera: string | null; campus: string | null }[];
    situacion: string | null;
    documentos: Documento[];
    tiposDocumento: TipoDocumento[];
    generos: { id: number; nombre: string }[];
    generos: { id: number; nombre: string }[];
}>();

/* ── Qué falta ─────────────────────────────────────────────────────────── */

const faltantes = computed(() => {
    const entregados = new Set(props.documentos.map((d) => d.documento_id));

    return props.tiposDocumento.filter((t) => t.obligatorio && !entregados.has(t.id));
});

const rechazados = computed(() => props.documentos.filter((d) => d.estado_clave === 'rechazado'));
const vencidos = computed(() => props.documentos.filter((d) => d.vencido && d.estado_clave !== 'rechazado'));
const enRevision = computed(() =>
    props.documentos.filter((d) => d.estado_clave !== 'aceptado' && d.estado_clave !== 'rechazado' && !d.vencido),
);
const aceptados = computed(() => props.documentos.filter((d) => d.estado_clave === 'aceptado' && !d.vencido));

const pendientes = computed(() => faltantes.value.length + rechazados.value.length + vencidos.value.length);

const obligatorios = computed(() => props.tiposDocumento.filter((t) => t.obligatorio).length);
const cubiertos = computed(() => Math.max(0, obligatorios.value - faltantes.value.length));

/*
 * El avance se mide contra los OBLIGATORIOS, no contra todo lo subido: quien
 * subió tres constancias opcionales y no el acta no va adelantado.
 */
const avance = computed(() =>
    obligatorios.value === 0 ? 100 : Math.round((cubiertos.value / obligatorios.value) * 100),
);

/** Primero lo que reclama algo; al final lo que ya está resuelto. */
const documentosOrdenados = computed(() => [
    ...rechazados.value,
    ...vencidos.value,
    ...enRevision.value,
    ...aceptados.value,
]);

const nombreCompleto = computed(() =>
    [props.persona.nombre, props.persona.primer_apellido, props.persona.segundo_apellido]
        .filter(Boolean)
        .join(' '),
);

const iniciales = computed(
    () => (props.persona.nombre?.[0] ?? '') + (props.persona.primer_apellido?.[0] ?? ''),
);

/* ── Mis datos ─────────────────────────────────────────────────────────── */

const form = useForm({
    nombre: props.persona.nombre ?? '',
    primer_apellido: props.persona.primer_apellido ?? '',
    segundo_apellido: props.persona.segundo_apellido ?? '',
    curp: props.persona.curp ?? '',
    rfc: props.persona.rfc ?? '',
    fecha_nacimiento: props.persona.fecha_nacimiento ?? '',
    genero_id: props.persona.genero_id ?? null,
    email: props.persona.email ?? '',
    celular: props.persona.celular ?? '',
});

function guardar(): void {
    form.put('/mi-expediente', { preserveScroll: true });
}

/* ── Documentos ────────────────────────────────────────────────────────── */

const formDoc = useForm({
    documento_id: null as number | null,
    archivo: null as File | null,
    descripcion: '',
    vigencia: '',
});

function subir(): void {
    formDoc.post('/mi-expediente/documentos', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => formDoc.reset(),
    });
}

/** Desde la tarjeta de pendientes: deja el tipo elegido y baja al formulario. */
function subirEste(tipoId: number): void {
    formDoc.documento_id = tipoId;
    document.getElementById('subir-documento')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function eliminar(doc: Documento): void {
    if (!confirm(`¿Eliminar "${doc.documento}"?`)) return;

    router.delete(`/mi-expediente/documentos/${doc.id}`, { preserveScroll: true });
}

/** El color habla del estado REAL: un aceptado que venció ya no vale. */
function colorDe(doc: Documento): string {
    if (doc.estado_clave === 'rechazado' || doc.vencido) return '#dc2626';
    if (doc.estado_clave === 'aceptado') return '#16a34a';

    return '#f59e0b';
}

function etiquetaDe(doc: Documento): string {
    if (doc.vencido && doc.estado_clave !== 'rechazado') return 'Vencido';

    return doc.estado ?? 'Pendiente';
}

/* ── Foto ──────────────────────────────────────────────────────────────── */

const formFoto = useForm({ foto: null as File | null });
const entradaFoto = ref<HTMLInputElement | null>(null);

function subirFoto(evento: Event): void {
    const archivos = (evento.target as HTMLInputElement).files;

    if (!archivos || archivos.length === 0) return;

    formFoto.foto = archivos[0];
    formFoto.post(`/personas/${props.persona.persona_id}/foto`, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            formFoto.reset();
            if (entradaFoto.value) entradaFoto.value.value = '';
        },
    });
}

function quitarFoto(): void {
    if (!confirm('¿Quitar la foto?')) return;

    router.delete(`/personas/${props.persona.persona_id}/foto`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Mi expediente" />

    <AppLayout titulo="Mi expediente">
        <!-- Quién soy y cómo me tiene registrado la escuela, de un vistazo. -->
        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-center gap-5">
                <div class="flex flex-col items-center gap-1.5">
                    <img v-if="persona.foto" :src="persona.foto" alt="" class="h-20 w-20 rounded-full object-cover" />
                    <span
                        v-else
                        class="flex h-20 w-20 items-center justify-center rounded-full text-2xl font-semibold"
                        :style="{
                            backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                            color: 'var(--color-acento)',
                        }"
                    >
                        {{ iniciales }}
                    </span>

                    <div class="flex items-center gap-2 text-xs">
                        <label class="cursor-pointer" :style="{ color: 'var(--color-acento)' }">
                            {{ persona.foto ? 'Cambiar' : 'Subir foto' }}
                            <input ref="entradaFoto" type="file" accept="image/*" class="hidden" @change="subirFoto" />
                        </label>
                        <BotonAccion v-if="persona.foto" variante="eliminar" texto="Quitar la foto" @click="quitarFoto" />
                    </div>
                    <p v-if="formFoto.errors.foto" class="text-xs text-red-600">{{ formFoto.errors.foto }}</p>
                </div>

                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-xl font-semibold text-contenido">{{ nombreCompleto || '—' }}</h2>

                    <!--
                        Una línea por inscripción: un alumno puede cursar dos
                        carreras a la vez, y enseñarle sólo una sería mentirle a
                        medias sobre en qué está inscrito.
                    -->
                    <div v-if="inscripciones.length" class="mt-2 space-y-1">
                        <p v-for="(i, n) in inscripciones" :key="n" class="flex flex-wrap items-center gap-2 text-xs">
                            <span
                                class="rounded-full px-2.5 py-1 font-mono"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                            >
                                {{ i.matricula ?? 'sin matrícula' }}
                            </span>
                            <span class="text-suave">{{ [i.carrera, i.campus].filter(Boolean).join(' · ') }}</span>
                        </p>
                    </div>

                    <p v-if="situacion" class="mt-2 text-xs text-suave">
                        Situación: <strong class="text-contenido">{{ situacion }}</strong> ·
                        la matrícula, la carrera y la situación las administra control escolar.
                    </p>
                </div>

                <div v-if="obligatorios > 0" class="w-full sm:w-48">
                    <div class="flex items-baseline justify-between">
                        <span class="text-xs text-suave">Documentos obligatorios</span>
                        <span class="text-sm font-semibold tabular-nums">{{ cubiertos }}/{{ obligatorios }}</span>
                    </div>
                    <div class="mt-1.5 h-2 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                        <div
                            class="h-2 rounded-full transition-all"
                            :style="{
                                width: `${avance}%`,
                                backgroundColor: avance === 100 ? '#16a34a' : 'var(--color-acento)',
                            }"
                        />
                    </div>
                </div>
            </div>
        </section>

        <!-- Lo que reclama acción, y sólo si existe. -->
        <section v-if="pendientes > 0" class="tarjeta mb-4 border-l-4 p-6" :style="{ borderLeftColor: '#dc2626' }">
            <h2 class="flex items-center gap-2 text-base font-semibold">
                <svg class="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                Tu expediente necesita atención
            </h2>

            <ul class="mt-4 space-y-2.5">
                <li
                    v-for="t in faltantes"
                    :key="`falta-${t.id}`"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span>
                        <strong>{{ t.nombre }}</strong>
                        <span class="ml-2 text-xs text-suave">obligatorio, sin entregar</span>
                    </span>
                    <button type="button" class="text-xs font-medium" :style="{ color: 'var(--color-acento)' }" @click="subirEste(t.id)">
                        Subirlo ahora
                    </button>
                </li>

                <li
                    v-for="d in rechazados"
                    :key="`rech-${d.id}`"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="min-w-0">
                        <strong>{{ d.documento }}</strong>
                        <span class="ml-2 text-xs text-red-600">rechazado</span>
                        <!-- El motivo va aquí: sin él, «rechazado» obliga a
                             adivinar qué corregir antes de volver a subirlo. -->
                        <span v-if="d.observaciones" class="mt-0.5 block text-xs italic text-suave">
                            «{{ d.observaciones }}»
                        </span>
                    </span>
                    <button
                        v-if="d.documento_id"
                        type="button"
                        class="shrink-0 text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="subirEste(d.documento_id)"
                    >
                        Volver a subirlo
                    </button>
                </li>

                <li
                    v-for="d in vencidos"
                    :key="`venc-${d.id}`"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span>
                        <strong>{{ d.documento }}</strong>
                        <span class="ml-2 text-xs text-red-600">venció el {{ d.vigencia }}</span>
                    </span>
                    <button
                        v-if="d.documento_id"
                        type="button"
                        class="text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="subirEste(d.documento_id)"
                    >
                        Subir el vigente
                    </button>
                </li>
            </ul>
        </section>

        <TarjetaSeccion
            titulo="Mis datos"
            descripcion="Manténlos al día: de aquí salen tus datos en constancias, kárdex y certificados."
            :icono="ICONOS.persona"
        >
            <form @submit.prevent="guardar">
                <div class="grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre(s)" requerido :error="form.errors.nombre" />
                    <CampoTexto v-model="form.primer_apellido" etiqueta="Primer apellido" requerido :error="form.errors.primer_apellido" />
                    <CampoTexto v-model="form.segundo_apellido" etiqueta="Segundo apellido" :error="form.errors.segundo_apellido" />

                    <CampoTexto v-model="form.curp" etiqueta="CURP" mono :error="form.errors.curp" />
                    <CampoTexto v-model="form.rfc" etiqueta="RFC" mono :error="form.errors.rfc" />
                    <CampoTexto
                        v-model="form.fecha_nacimiento"
                        etiqueta="Fecha de nacimiento"
                        tipo="date"
                        :error="form.errors.fecha_nacimiento"
                    />

                    <CampoSelect
                        v-model="form.genero_id"
                        etiqueta="Género"
                        requerido
                        :opciones="generos.map((g) => ({ valor: g.id, texto: g.nombre }))"
                        vacio="Selecciona…"
                        :error="form.errors.genero_id"
                    />

                    <CampoTexto v-model="form.celular" etiqueta="Celular" :error="form.errors.celular" />

                    <CampoTexto v-model="form.email" etiqueta="Correo personal" tipo="email" :error="form.errors.email" />
                    <CampoTexto
                        :model-value="persona.correo_institucional ?? '—'"
                        etiqueta="Correo institucional"
                        deshabilitado
                        ayuda="Lo asigna la escuela."
                    />
                </div>

                <BotonPrincipal :procesando="form.processing" texto="Guardar mis datos" class="mt-5" />
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion
            titulo="Mis documentos"
            descripcion="Cada carga queda pendiente de revisión; volver a subir el mismo tipo reemplaza el archivo anterior."
            :icono="ICONOS.documento"
            sin-relleno
            class="mt-4"
        >
            <ul v-if="documentosOrdenados.length">
                <li
                    v-for="doc in documentosOrdenados"
                    :key="doc.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ doc.documento }}</p>
                        <p v-if="doc.descripcion" class="text-xs text-suave">{{ doc.descripcion }}</p>
                        <p v-if="doc.vigencia" class="text-xs" :class="doc.vencido ? 'font-medium text-red-600' : 'text-suave'">
                            Vigencia {{ doc.vigencia }}<span v-if="doc.vencido"> — vencido</span>
                        </p>
                        <p v-if="doc.observaciones" class="mt-0.5 text-xs italic text-amber-700">
                            {{ doc.observaciones }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :style="{
                                backgroundColor: `color-mix(in srgb, ${colorDe(doc)} 14%, transparent)`,
                                color: colorDe(doc),
                            }"
                        >
                            {{ etiquetaDe(doc) }}
                        </span>
                        <a
                            :href="`/mi-expediente/documentos/${doc.id}/descargar`"
                            class="text-sm"
                            :style="{ color: 'var(--color-acento)' }"
                        >
                            Descargar
                        </a>
                        <BotonAccion v-if="doc.estado_clave !== 'aceptado'" variante="eliminar" @click="eliminar(doc)" />
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm text-suave">
                Todavía no has cargado documentos.
            </p>

            <form
                id="subir-documento"
                class="border-t px-6 py-5"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="subir"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-4">
                        <CampoSelect
                            v-model="formDoc.documento_id"
                            etiqueta="Tipo de documento"
                            :opciones="tiposDocumento.map((t) => ({
                                valor: t.id,
                                texto: t.obligatorio ? `${t.nombre} (obligatorio)` : t.nombre,
                            }))"
                            vacio="Selecciona…"
                            :error="formDoc.errors.documento_id"
                        />
                        <CampoTexto
                            v-model="formDoc.vigencia"
                            etiqueta="Vigencia"
                            tipo="date"
                            :error="formDoc.errors.vigencia"
                            ayuda="Solo si vence."
                        />
                    </div>

                    <div>
                        <ZonaArchivo
                            accept=".pdf,.jpg,.jpeg,.png"
                            texto="Arrastra el documento o haz clic para elegirlo"
                            ayuda="PDF o imagen, máximo 5 MB."
                            :cargado="formDoc.archivo?.name ?? null"
                            :ocupado="formDoc.processing"
                            @archivo="(f) => (formDoc.archivo = f)"
                        />
                        <p v-if="formDoc.errors.archivo" class="mt-1 text-xs text-red-600">
                            {{ formDoc.errors.archivo }}
                        </p>
                    </div>
                </div>

                <BotonPrincipal
                    :procesando="formDoc.processing"
                    :deshabilitado="!formDoc.documento_id || !formDoc.archivo"
                    texto="Subir documento"
                    cargando="Subiendo…"
                    icono="ninguno"
                    class="mt-4"
                />
            </form>
        </TarjetaSeccion>
    </AppLayout>
</template>
