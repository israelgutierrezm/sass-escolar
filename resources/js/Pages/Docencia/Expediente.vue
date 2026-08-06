<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import TitulosDocente from '@/Components/TitulosDocente.vue';
import FormulariosAsignados from '@/Components/FormulariosAsignados.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';
import { ICONOS } from '@/iconos';

/**
 * Mi expediente, desde el lado del docente.
 *
 * ── Lo primero es qué falta ────────────────────────────────────────────────
 * La pantalla era una lista de lo que YA está: cuatro documentos con su
 * pastilla de estado. Pero nadie entra aquí a admirar lo que ya entregó; se
 * entra porque la escuela pidió algo, o porque llegó un correo diciendo que la
 * constancia venció. Lo que hacía falta —qué obligatorio sigue sin subir, qué
 * caducó, qué rechazaron y por qué— había que reconstruirlo comparando la lista
 * contra el desplegable de tipos, uno por uno.
 *
 * Ahora eso se calcula y encabeza la pantalla. Si no hay nada que hacer, la
 * tarjeta ni aparece: un tablero que siempre dice algo deja de leerse.
 *
 * ── El orden de la lista ───────────────────────────────────────────────────
 * Los documentos ya no salen en el orden en que se subieron sino por lo que
 * reclaman: rechazado, vencido, pendiente y al final lo aceptado, que es lo
 * único que no pide nada de nadie.
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
    docente: { clave_profesor: string | null; tipo: string | null; situacion: string | null; campus: string[] };
    documentos: Documento[];
    titulos: { id: number; grado: string; titulo_obtenido: string; cedula: string | null; institucion: string | null; anio: number | null; archivo: string | null }[];
    tiposDocumento: TipoDocumento[];
    generos: { id: number; nombre: string }[];
    /** Los bloques de datos que le tocan, de `ResolutorFormularios`. */
    formularios: Record<string, any>[];
}>();

/* ── Qué falta ─────────────────────────────────────────────────────────── */

/** Los obligatorios que no tienen ni un archivo subido. */
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

/** Lo que reclama que el docente haga algo. Si es cero, no hay tarjeta. */
const pendientes = computed(() => faltantes.value.length + rechazados.value.length + vencidos.value.length);

const obligatorios = computed(() => props.tiposDocumento.filter((t) => t.obligatorio).length);
const cubiertos = computed(() => Math.max(0, obligatorios.value - faltantes.value.length));

/*
 * El avance se mide contra los OBLIGATORIOS, no contra todo lo subido: un
 * docente con seis constancias opcionales y sin cédula no está al 120%, está
 * incompleto.
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
    form.put('/docencia/expediente', { preserveScroll: true });
}

/* ── Documentos ────────────────────────────────────────────────────────── */

const formDoc = useForm({
    documento_id: null as number | null,
    archivo: null as File | null,
    descripcion: '',
    vigencia: '',
});

function subir(): void {
    formDoc.post('/docencia/expediente/documentos', {
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

    router.delete(`/docencia/expediente/documentos/${doc.id}`, { preserveScroll: true });
}

/** El color habla del estado REAL: un aceptado que venció ya no vale. */
function colorDe(doc: Documento): string {
    if (doc.estado_clave === 'rechazado') return '#dc2626';
    if (doc.vencido) return '#dc2626';
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
        <!--
            Quién soy y cómo me tiene registrado la escuela, de un vistazo.

            La foto vivía dentro del formulario de datos, entre el título y los
            campos, donde ni se veía ni pertenecía. Aquí acompaña al nombre, que
            es lo que identifica, y deja el formulario para lo que se teclea.
        -->
        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-center gap-5">
                <div class="flex flex-col items-center gap-1.5">
                    <img
                        v-if="persona.foto"
                        :src="persona.foto"
                        alt=""
                        class="h-20 w-20 rounded-full object-cover"
                    />
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
                        Datos de solo lectura: los administra control escolar. En
                        pastillas y no en una rejilla de definiciones porque son
                        etiquetas de identidad, no un formulario, y ocupaban
                        cuatro columnas para decir tres palabras.
                    -->
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span
                            v-if="docente.clave_profesor"
                            class="rounded-full px-2.5 py-1 font-mono"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                        >
                            {{ docente.clave_profesor }}
                        </span>
                        <span
                            v-for="dato in [docente.tipo, docente.situacion, ...docente.campus].filter(Boolean)"
                            :key="dato as string"
                            class="rounded-full border px-2.5 py-1 text-suave"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            {{ dato }}
                        </span>
                    </div>

                    <p class="mt-2 text-xs text-suave">
                        La clave, el tipo, la situación y el campus los administra control escolar.
                        Si algo está mal, pídeles la corrección.
                    </p>
                </div>

                <!-- El avance del expediente, medido contra lo obligatorio. -->
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

        <!--
            Lo que reclama acción, y sólo si existe.

            Una tarjeta que siempre dice algo —«todo en orden»— deja de leerse a
            la tercera visita, y entonces tampoco se lee el día que sí hay algo.
        -->
        <section
            v-if="pendientes > 0"
            class="tarjeta mb-4 border-l-4 p-6"
            :style="{ borderLeftColor: '#dc2626' }"
        >
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
                    <button
                        type="button"
                        class="text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="subirEste(t.id)"
                    >
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
                        <!-- El motivo va aquí y no sólo abajo: sin él, «rechazado»
                             obliga a adivinar qué corregir antes de resubir. -->
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

        <!-- Mis títulos / grados (los administra el propio docente) -->
        <TitulosDocente :titulos="titulos" base="/docencia/expediente/titulos" :puede-editar="true" />

        <!--
            Los formularios que la escuela le pide. Los llena él mismo, igual
            que sube sus documentos: si sólo pudiera hacerlo control escolar,
            capturar los datos de cada docente recaería en quien menos los sabe.
            Se ocultan cuando no le toca ninguno.
        -->
        <FormulariosAsignados
            v-if="formularios.length"
            :formularios="formularios"
            titular="docente"
            base-captura="/docencia/expediente/formularios"
            :puede-capturar="true"
            class="mt-4"
        />

        <TarjetaSeccion
            titulo="Mis datos"
            descripcion="Manténlos al día: de aquí salen tus datos en actas y documentos oficiales."
            :icono="ICONOS.persona"
            class="mt-4"
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
                        <p
                            v-if="doc.vigencia"
                            class="text-xs"
                            :class="doc.vencido ? 'font-medium text-red-600' : 'text-suave'"
                        >
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
                            :href="`/docencia/expediente/documentos/${doc.id}/descargar`"
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

                    <!--
                        Arrastrar y soltar, igual que los .cer de titulación: es
                        el mismo gesto en toda la plataforma y no había razón
                        para que aquí siguiera siendo un <input type=file> pelón.
                    -->
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
