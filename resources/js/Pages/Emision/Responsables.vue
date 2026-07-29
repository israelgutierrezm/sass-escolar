<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Responsable {
    id: number;
    nombre_completo: string;
    curp: string;
    cargo: string | null;
    cargo_id: number | null;
    titulo: string | null;
    titulo_profesional_id: number | null;
    activo: boolean;
    tiene_cer_guardado: boolean;
    tiene_key: boolean;
    cer_titular: string | null;
    cer_serial: string | null;
    vigencia_inicio: string | null;
    vigencia_fin: string | null;
}

interface DatosCert {
    titular: string;
    nombre: string;
    apellido_paterno: string;
    apellido_materno: string;
    curp: string;
    serial: string;
    vigencia_inicio: string;
    vigencia_fin: string;
}

const props = defineProps<{
    seccion: string;
    tituloSeccion: string;
    maximo: number;
    activos: Responsable[];
    historial: Responsable[];
    cargos: { id: number; nombre: string }[];
    titulos: { id: number; abreviatura: string; descripcion: string }[];
}>();

const base = computed(() => `/${props.seccion}/configuracion/responsables`);
const puedeAgregar = computed(() => props.activos.length < props.maximo);

const opcionesCargo = computed(() => props.cargos.map((c) => ({ valor: c.id, texto: c.nombre })));
const opcionesTitulo = computed(() => props.titulos.map((t) => ({ valor: t.id, texto: `${t.abreviatura} — ${t.descripcion}` })));

/** Sube un .cer al backend y devuelve sus datos leídos (o null si falla). */
async function leerCert(archivo: File): Promise<DatosCert | null> {
    if (!archivo.name.toLowerCase().endsWith('.cer')) {
        toast.error('El archivo debe ser un certificado con extensión .cer');

        return null;
    }
    try {
        const datos = new FormData();
        datos.append('certificado', archivo);
        const { data } = await axios.post<DatosCert>(`${base.value}/leer-certificado`, datos);

        return data;
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'No se pudo leer el certificado.');

        return null;
    }
}

// ---------- ALTA ----------
const cert = ref<DatosCert | null>(null);
const leyendo = ref(false);
const arrastrando = ref(false);
const entrada = ref<HTMLInputElement | null>(null);

const formAlta = useForm<{ certificado: File | null; cargo_id: number | null; titulo_profesional_id: number | null; guardar_cer: boolean }>({
    certificado: null,
    cargo_id: null,
    titulo_profesional_id: null,
    guardar_cer: false,
});

const altaLista = computed(() => cert.value !== null && !!formAlta.cargo_id && !!formAlta.titulo_profesional_id);

async function elegirCertAlta(archivo: File | null): Promise<void> {
    cert.value = null;
    formAlta.certificado = archivo;
    if (!archivo) {
        return;
    }
    leyendo.value = true;
    const datos = await leerCert(archivo);
    leyendo.value = false;
    if (datos) {
        cert.value = datos;
        toast.success(`Certificado leído: ${datos.titular}`);
    } else {
        formAlta.certificado = null;
    }
}

function limpiarCertAlta(): void {
    cert.value = null;
    formAlta.certificado = null;
    if (entrada.value) entrada.value.value = '';
}

function guardarAlta(): void {
    formAlta.post(base.value, {
        onSuccess: () => {
            formAlta.reset();
            limpiarCertAlta();
        },
    });
}

// ---------- EDICIÓN ----------
const editando = ref<Responsable | null>(null);
const nombreCertEdit = ref<string | null>(null);
const nombreLlaveEdit = ref<string | null>(null);
const entradaCertEdit = ref<HTMLInputElement | null>(null);
const entradaLlaveEdit = ref<HTMLInputElement | null>(null);

const formEdit = useForm<{ certificado: File | null; llave: File | null; llave_password: string; cargo_id: number | null; titulo_profesional_id: number | null }>({
    certificado: null,
    llave: null,
    llave_password: '',
    cargo_id: null,
    titulo_profesional_id: null,
});

function abrirEdicion(r: Responsable): void {
    editando.value = r;
    nombreCertEdit.value = null;
    nombreLlaveEdit.value = null;
    formEdit.reset();
    formEdit.cargo_id = r.cargo_id;
    formEdit.titulo_profesional_id = r.titulo_profesional_id;
}

function cerrarEdicion(): void {
    editando.value = null;
    formEdit.reset();
}

function elegirCertEdit(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    formEdit.certificado = archivo && archivo.name.toLowerCase().endsWith('.cer') ? archivo : null;
    nombreCertEdit.value = formEdit.certificado?.name ?? null;
    if (archivo && !formEdit.certificado) toast.error('El certificado debe tener extensión .cer');
}

function elegirLlaveEdit(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    formEdit.llave = archivo;
    nombreLlaveEdit.value = archivo?.name ?? null;
}

function guardarEdicion(): void {
    if (!editando.value) return;
    formEdit.post(`${base.value}/${editando.value.id}/actualizar`, {
        onSuccess: () => cerrarEdicion(),
    });
}

function desactivar(r: Responsable): void {
    if (!confirm(`¿Desactivar a ${r.nombre_completo}? Quedará en el historial y dejará de firmar.`)) return;
    router.put(`${base.value}/${r.id}/desactivar`, {}, { preserveScroll: true });
}

function eliminar(r: Responsable): void {
    if (!confirm(`¿Eliminar del historial a ${r.nombre_completo}?`)) return;
    router.delete(`${base.value}/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Responsables · ${tituloSeccion}`" />

    <AppLayout :titulo="`${tituloSeccion} · Responsables`">
        <!-- Responsables activos -->
        <section v-if="activos.length" class="tarjeta mb-6 p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Responsables activos</h2>
                <span class="text-sm" :style="{ color: 'var(--color-suave)' }">{{ activos.length }} de {{ maximo }}</span>
            </div>

            <div class="mt-4 space-y-4">
                <div v-for="r in activos" :key="r.id" class="rounded-xl border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ r.titulo ? `${r.titulo} ` : '' }}{{ r.nombre_completo }}</p>
                            <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.curp }}</p>
                            <p class="mt-1 text-sm">{{ r.cargo ?? '—' }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-sm">
                            <button type="button" class="font-medium" :style="{ color: 'var(--color-acento)' }" @click="abrirEdicion(r)">Editar</button>
                            <button type="button" class="text-red-600 hover:text-red-700" @click="desactivar(r)">Desactivar</button>
                        </div>
                    </div>

                    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t pt-3 text-xs" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <div class="flex gap-1"><dt>Serie:</dt><dd class="font-mono">{{ r.cer_serial }}</dd></div>
                        <div class="flex gap-1"><dt>Vigencia:</dt><dd>{{ r.vigencia_inicio }} – {{ r.vigencia_fin }}</dd></div>
                        <div class="flex gap-1"><dt>.cer:</dt><dd>{{ r.tiene_cer_guardado ? 'guardado' : 'no guardado' }}</dd></div>
                        <div class="flex gap-1"><dt>.key:</dt><dd>{{ r.tiene_key ? 'cargada' : 'no cargada' }}</dd></div>
                    </dl>

                    <!-- Edición inline -->
                    <div v-if="editando && editando.id === r.id" class="mt-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <form class="space-y-4" @submit.prevent="guardarEdicion">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <CampoSelect v-model="formEdit.titulo_profesional_id" etiqueta="Título profesional" requerido vacio="Seleccione una opción" :opciones="opcionesTitulo" :error="formEdit.errors.titulo_profesional_id" />
                                <CampoSelect v-model="formEdit.cargo_id" etiqueta="Cargo" requerido vacio="Seleccione una opción" :opciones="opcionesCargo" :error="formEdit.errors.cargo_id" />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <!-- Renovar certificado -->
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Actualizar certificado (.cer)</label>
                                    <input ref="entradaCertEdit" type="file" accept=".cer" class="hidden" @change="elegirCertEdit" />
                                    <button type="button" class="w-full rounded-lg border px-3 py-2 text-left text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="entradaCertEdit?.click()">
                                        <span v-if="nombreCertEdit" class="truncate">{{ nombreCertEdit }}</span>
                                        <span v-else :style="{ color: 'var(--color-suave)' }">Dejar vacío para conservar el actual</span>
                                    </button>
                                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">Renueva el cert de la misma persona (p. ej. si venció).</p>
                                </div>

                                <!-- Cargar llave -->
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Llave privada (.key)</label>
                                    <input ref="entradaLlaveEdit" type="file" accept=".key" class="hidden" @change="elegirLlaveEdit" />
                                    <button type="button" class="w-full rounded-lg border px-3 py-2 text-left text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="entradaLlaveEdit?.click()">
                                        <span v-if="nombreLlaveEdit" class="truncate">{{ nombreLlaveEdit }}</span>
                                        <span v-else :style="{ color: 'var(--color-suave)' }">{{ r.tiene_key ? 'Llave cargada — súbela de nuevo para reemplazar' : 'Selecciona el .key' }}</span>
                                    </button>
                                    <p v-if="formEdit.errors.llave" class="mt-1 text-xs text-red-600">{{ formEdit.errors.llave }}</p>
                                </div>

                                <div v-if="formEdit.llave">
                                    <CampoTexto v-model="formEdit.llave_password" etiqueta="Contraseña de la llave" tipo="password" requerido :error="formEdit.errors.llave_password" />
                                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">Solo se usa para validar la llave; no se almacena. Al firmar se pedirá de nuevo.</p>
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <BotonPrincipal :procesando="formEdit.processing" texto="Guardar cambios" />
                                <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="cerrarEdicion">Cancelar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <!-- Alta -->
        <section v-if="puedeAgregar && !editando" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Agregar responsable</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Carga el certificado (<b>.cer</b>) del responsable; sus datos se leen del archivo y solo
                completas el título y el cargo. Todos los campos son obligatorios.
            </p>

            <form class="mt-5 space-y-5" @submit.prevent="guardarAlta">
                <div>
                    <label class="mb-1 block text-sm font-medium">Certificado (.cer) <span class="text-red-500">*</span></label>
                    <input ref="entrada" type="file" accept=".cer" class="hidden" @change="elegirCertAlta(($event.target as HTMLInputElement).files?.[0] ?? null)" />

                    <div
                        v-if="!cert"
                        class="zona"
                        :class="{ 'zona--activa': arrastrando }"
                        role="button"
                        tabindex="0"
                        @click="entrada?.click()"
                        @keydown.enter.prevent="entrada?.click()"
                        @dragover.prevent="arrastrando = true"
                        @dragenter.prevent="arrastrando = true"
                        @dragleave.prevent="arrastrando = false"
                        @drop.prevent="arrastrando = false; elegirCertAlta($event.dataTransfer?.files?.[0] ?? null)"
                    >
                        <svg class="mx-auto h-9 w-9" :style="{ color: 'var(--color-acento)' }" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                        </svg>
                        <p class="mt-2 text-sm font-medium">
                            <span v-if="leyendo">Leyendo certificado…</span>
                            <span v-else>Arrastra el <b>.cer</b> aquí o haz clic para seleccionarlo</span>
                        </p>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">Solo el archivo .cer del responsable</p>
                    </div>

                    <div v-else class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <span class="flex min-w-0 items-center gap-2">
                            <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <span class="truncate">{{ cert.titular }}</span>
                        </span>
                        <button type="button" class="shrink-0 font-medium" :style="{ color: 'var(--color-acento)' }" @click="entrada?.click()">Cambiar</button>
                    </div>
                </div>

                <div v-if="cert" class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto :model-value="cert.nombre" etiqueta="Nombre" deshabilitado />
                    <CampoTexto :model-value="cert.curp" etiqueta="CURP" mono deshabilitado />
                    <CampoTexto :model-value="cert.apellido_paterno" etiqueta="Apellido paterno" deshabilitado />
                    <CampoTexto :model-value="cert.apellido_materno || '—'" etiqueta="Apellido materno" deshabilitado />

                    <CampoSelect v-model="formAlta.titulo_profesional_id" etiqueta="Título profesional" requerido vacio="Seleccione una opción" :opciones="opcionesTitulo" :error="formAlta.errors.titulo_profesional_id" />
                    <CampoSelect v-model="formAlta.cargo_id" etiqueta="Cargo" requerido vacio="Seleccione una opción" :opciones="opcionesCargo" :error="formAlta.errors.cargo_id" />

                    <div class="sm:col-span-2 rounded-lg border p-3 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <label class="flex items-start gap-2">
                            <input v-model="formAlta.guardar_cer" type="checkbox" class="mt-0.5 rounded" />
                            <span>
                                <span class="font-medium">Guardar mi .cer</span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">Se almacena el certificado para no volver a subirlo; después podrás cargar el .key y firmar solo con la contraseña.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <BotonPrincipal :procesando="formAlta.processing" texto="Guardar responsable" :deshabilitado="!altaLista" />
                </div>
            </form>
        </section>

        <section v-else-if="!editando" class="tarjeta p-6 text-sm" :style="{ color: 'var(--color-suave)' }">
            Ya hay {{ maximo }} responsable(s) activo(s) para {{ tituloSeccion.toLowerCase() }}. Desactiva uno
            para poder agregar otro.
        </section>

        <!-- Historial -->
        <section v-if="historial.length" class="tarjeta mt-6 p-6">
            <h2 class="text-base font-semibold">Historial</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Responsables desactivados. Se conservan porque los documentos que firmaron quedan ligados a ellos.
            </p>

            <ul class="mt-4 divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="r in historial" :key="r.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ r.titulo ? `${r.titulo} ` : '' }}{{ r.nombre_completo }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.cargo ?? '—' }} · serie {{ r.cer_serial }} · vigencia {{ r.vigencia_inicio }} – {{ r.vigencia_fin }}</p>
                    </div>
                    <button type="button" class="shrink-0 text-sm text-red-600 hover:text-red-700" @click="eliminar(r)">Eliminar</button>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>

<style scoped>
.zona {
    display: block;
    width: 100%;
    cursor: pointer;
    border-radius: 0.75rem;
    border: 2px dashed var(--color-borde);
    padding: 2rem 1rem;
    text-align: center;
    transition:
        border-color 0.15s ease,
        background-color 0.15s ease;
}

.zona:hover,
.zona:focus-visible,
.zona--activa {
    outline: none;
    border-color: var(--color-acento);
    background-color: color-mix(in srgb, var(--color-acento) 6%, transparent);
}
</style>
