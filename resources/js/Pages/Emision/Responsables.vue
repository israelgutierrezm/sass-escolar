<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PestanasPagina from '@/Components/PestanasPagina.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

interface Certificado {
    id: number;
    serie: string;
    vigencia_inicio: string | null;
    vigencia_fin: string | null;
    vigente: boolean;
    registrado: string | null;
    tiene_cer_guardado: boolean;
    tiene_key: boolean;
}

interface Responsable {
    id: number;
    nombre_completo: string;
    curp: string;
    cargo: string | null;
    cargo_id: number | null;
    titulo: string | null;
    titulo_profesional_id: number | null;
    activo: boolean;
    cer_serial: string | null;
    vigencia_inicio: string | null;
    vigencia_fin: string | null;
    vigente_hoy: boolean | null;
    dias_restantes: number | null;
    tiene_cer_guardado: boolean;
    tiene_key: boolean;
    certificados: Certificado[];
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
    responsables: Responsable[];
    cargos: { id: number; nombre: string }[];
    titulos: { id: number; abreviatura: string; descripcion: string }[];
}>();

const base = computed(() => `/${props.seccion}/configuracion/responsables`);
const puedeAgregar = computed(() => props.activos.length < props.maximo);
const tab = ref<'activos' | 'historial'>('activos');

// Estado de vigencia del certificado: rojo vencido, ámbar por vencer (≤30 días),
// verde vigente. Se usa como color del punto y del texto de la insignia.
function estadoVigencia(r: Responsable): { color: string; backgroundColor: string } {
    const color = r.vigente_hoy === false ? '#dc2626' : (r.dias_restantes !== null && r.dias_restantes <= 30 ? '#d97706' : '#16a34a');
    return { color, backgroundColor: `color-mix(in srgb, ${color} 15%, transparent)` };
}

function textoVigencia(r: Responsable): string {
    if (r.vigente_hoy === false) return 'Vencido';
    if (r.dias_restantes !== null && r.dias_restantes <= 30) return `Por vencer · ${r.dias_restantes} día(s)`;
    return `Vigente · ${r.dias_restantes} día(s)`;
}

const opcionesCargo = computed(() => props.cargos.map((c) => ({ valor: c.id, texto: c.nombre })));
const opcionesTitulo = computed(() => props.titulos.map((t) => ({ valor: t.id, texto: `${t.abreviatura} — ${t.descripcion}` })));

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
const leyendoAlta = ref(false);
const formAlta = useForm<{ certificado: File | null; cargo_id: number | null; titulo_profesional_id: number | null; guardar_cer: boolean }>({
    certificado: null,
    cargo_id: null,
    titulo_profesional_id: null,
    guardar_cer: false,
});
const altaLista = computed(() => cert.value !== null && !!formAlta.cargo_id && !!formAlta.titulo_profesional_id);

async function certAlta(archivo: File | null): Promise<void> {
    cert.value = null;
    formAlta.certificado = null;
    if (!archivo) return;
    leyendoAlta.value = true;
    const datos = await leerCert(archivo);
    leyendoAlta.value = false;
    if (datos) {
        cert.value = datos;
        formAlta.certificado = archivo;
        toast.success(`Certificado leído: ${datos.titular}`);
    }
}

function guardarAlta(): void {
    formAlta.post(base.value, {
        onSuccess: () => {
            formAlta.reset();
            cert.value = null;
        },
    });
}

// ---------- EDICIÓN ----------
const editando = ref<Responsable | null>(null);
const certEdit = ref<DatosCert | null>(null);
const nombreLlave = ref<string | null>(null);
const leyendoEdit = ref(false);
const formEdit = useForm<{ certificado: File | null; guardar_cer: boolean; llave: File | null; llave_password: string; guardar_key: boolean; cargo_id: number | null; titulo_profesional_id: number | null }>({
    certificado: null,
    guardar_cer: false,
    llave: null,
    llave_password: '',
    guardar_key: true,
    cargo_id: null,
    titulo_profesional_id: null,
});

function abrirEdicion(r: Responsable): void {
    editando.value = r;
    certEdit.value = null;
    nombreLlave.value = null;
    formEdit.reset();
    formEdit.cargo_id = r.cargo_id;
    formEdit.titulo_profesional_id = r.titulo_profesional_id;
}

function cerrarEdicion(): void {
    editando.value = null;
    formEdit.reset();
    certEdit.value = null;
    nombreLlave.value = null;
}

async function certEditar(archivo: File | null): Promise<void> {
    certEdit.value = null;
    formEdit.certificado = null;
    if (!archivo || !editando.value) return;
    leyendoEdit.value = true;
    const datos = await leerCert(archivo);
    leyendoEdit.value = false;
    if (!datos) return;
    if (datos.curp.toUpperCase() !== editando.value.curp.toUpperCase()) {
        toast.error('El certificado es de otra persona (CURP distinta). Para cambiar de responsable, desactiva este y agrega uno nuevo.');

        return;
    }
    certEdit.value = datos;
    formEdit.certificado = archivo;
    toast.success('Certificado nuevo leído.');
}

function llaveEditar(archivo: File | null): void {
    formEdit.llave = archivo;
    nombreLlave.value = archivo?.name ?? null;
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
    if (!confirm(`¿Eliminar del historial a ${r.nombre_completo} y todos sus certificados?`)) return;
    router.delete(`${base.value}/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Responsables · ${tituloSeccion}`" />

    <AppLayout :titulo="`${tituloSeccion} · Responsables`">
        <!-- Pestañas -->
        <PestanasPagina
            :pestanas="[
                { clave: 'activos', etiqueta: 'Responsables' },
                { clave: 'historial', etiqueta: `Historial (${responsables.length})` },
            ]"
            :model-value="tab"
            @update:model-value="tab = $event as any"
        />

        <!-- ===== TAB RESPONSABLES ===== -->
        <template v-if="tab === 'activos'">
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
                                <button type="button" class="font-medium" :style="{ color: 'var(--color-acento)' }" @click="editando && editando.id === r.id ? cerrarEdicion() : abrirEdicion(r)">
                                    {{ editando && editando.id === r.id ? 'Cerrar' : 'Editar' }}
                                </button>
                                <button type="button" class="text-red-600 hover:text-red-700" @click="desactivar(r)">Desactivar</button>
                            </div>
                        </div>

                        <dl class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 border-t pt-3 text-xs" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <div class="flex gap-1"><dt>Serie vigente:</dt><dd class="font-mono">{{ r.cer_serial ?? '—' }}</dd></div>
                            <div class="flex gap-1"><dt>Vigencia:</dt><dd>{{ r.vigencia_inicio }} – {{ r.vigencia_fin }}</dd></div>
                            <!-- Estado de vigencia: verde vigente, ámbar por vencer, rojo vencido. -->
                            <span
                                v-if="r.vigente_hoy !== null"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium"
                                :style="estadoVigencia(r)"
                            >
                                <span class="inline-block h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: estadoVigencia(r).color }" />
                                {{ textoVigencia(r) }}
                            </span>
                            <div class="flex gap-1"><dt>.cer:</dt><dd>{{ r.tiene_cer_guardado ? 'guardado' : 'no guardado' }}</dd></div>
                            <div class="flex gap-1"><dt>.key:</dt><dd>{{ r.tiene_key ? 'cargada' : 'no cargada' }}</dd></div>
                        </dl>

                        <!-- Edición inline -->
                        <div v-if="editando && editando.id === r.id" class="mt-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                            <form class="space-y-5" @submit.prevent="guardarEdicion">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <CampoSelect v-model="formEdit.titulo_profesional_id" etiqueta="Título profesional" requerido vacio="Seleccione una opción" :opciones="opcionesTitulo" :error="formEdit.errors.titulo_profesional_id" />
                                    <CampoSelect v-model="formEdit.cargo_id" etiqueta="Cargo" requerido vacio="Seleccione una opción" :opciones="opcionesCargo" :error="formEdit.errors.cargo_id" />
                                </div>

                                <!-- Renovar certificado -->
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Actualizar certificado (.cer)</label>
                                    <ZonaArchivo accept=".cer" texto="Arrastra el .cer o haz clic para seleccionarlo" ayuda="Renueva el cert de esta persona (p. ej. si venció); déjalo vacío para conservar el actual." :cargado="certEdit?.titular ?? null" :ocupado="leyendoEdit" @archivo="certEditar" />
                                    <label v-if="certEdit" class="mt-2 flex items-start gap-2 text-sm">
                                        <input v-model="formEdit.guardar_cer" type="checkbox" class="mt-0.5 rounded" />
                                        <span>
                                            <span class="font-medium">Deseo guardar mi certificado para no tener que volver a cargarlo</span>
                                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">Requerido para poder cargar la llave. El certificado es público; la contraseña jamás se almacena.</span>
                                        </span>
                                    </label>
                                </div>

                                <!-- Cargar llave -->
                                <div>
                                    <label class="mb-1 block text-sm font-medium">Llave privada (.key)</label>
                                    <ZonaArchivo accept=".key" texto="Arrastra el .key o haz clic para seleccionarlo" :ayuda="r.tiene_key ? 'Ya hay una llave cargada; sube otra para reemplazarla.' : 'Cárgala para firmar solo con la contraseña.'" :cargado="nombreLlave" @archivo="llaveEditar" />
                                    <p v-if="formEdit.errors.llave" class="mt-1 text-xs text-red-600">{{ formEdit.errors.llave }}</p>
                                    <div v-if="formEdit.llave" class="mt-2 space-y-2">
                                        <CampoTexto v-model="formEdit.llave_password" etiqueta="Contraseña de la llave" tipo="password" requerido :error="formEdit.errors.llave_password" />
                                        <label class="flex items-start gap-2 text-sm">
                                            <input v-model="formEdit.guardar_key" type="checkbox" class="mt-0.5 rounded" />
                                            <span>
                                                <span class="font-medium">Deseo guardar mi llave para firmar solo con la contraseña</span>
                                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">Se guarda cifrada. La contraseña <b>jamás se almacena</b>: se pide solo al momento de firmar.</span>
                                            </span>
                                        </label>
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
                        <ZonaArchivo accept=".cer" texto="Arrastra el .cer aquí o haz clic para seleccionarlo" ayuda="Solo el archivo .cer del responsable" :cargado="cert?.titular ?? null" :ocupado="leyendoAlta" @archivo="certAlta" />
                    </div>

                    <div v-if="cert" class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto :model-value="cert.nombre" etiqueta="Nombre" deshabilitado />
                        <CampoTexto :model-value="cert.curp" etiqueta="CURP" mono deshabilitado />
                        <CampoTexto :model-value="cert.apellido_paterno" etiqueta="Apellido paterno" deshabilitado />
                        <CampoTexto :model-value="cert.apellido_materno || '—'" etiqueta="Apellido materno" deshabilitado />

                        <CampoSelect v-model="formAlta.titulo_profesional_id" etiqueta="Título profesional" requerido vacio="Seleccione una opción" :opciones="opcionesTitulo" :error="formAlta.errors.titulo_profesional_id" />
                        <CampoSelect v-model="formAlta.cargo_id" etiqueta="Cargo" requerido vacio="Seleccione una opción" :opciones="opcionesCargo" :error="formAlta.errors.cargo_id" />

                        <label class="sm:col-span-2 flex items-start gap-2 rounded-lg border p-3 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <input v-model="formAlta.guardar_cer" type="checkbox" class="mt-0.5 rounded" />
                            <span>
                                <span class="font-medium">Deseo guardar mi certificado para no tener que volver a cargarlo</span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">El certificado es público. Guardarlo te permitirá después cargar la llave y firmar solo con la contraseña (la contraseña jamás se almacena).</span>
                            </span>
                        </label>
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
        </template>

        <!-- ===== TAB HISTORIAL ===== -->
        <template v-else>
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Historial de responsables</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Un registro por persona (por CURP), con su historial de certificados. Se conserva porque los
                    documentos firmados quedan ligados al responsable y al certificado con que se firmaron.
                </p>

                <p v-if="!responsables.length" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">Aún no hay responsables registrados.</p>

                <div v-for="r in responsables" :key="r.id" class="mt-4 rounded-xl border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">
                                {{ r.titulo ? `${r.titulo} ` : '' }}{{ r.nombre_completo }}
                                <span class="ml-1 rounded-full px-2 py-0.5 text-xs" :style="{ backgroundColor: r.activo ? 'color-mix(in srgb, #16a34a 16%, transparent)' : 'var(--color-fondo)', color: r.activo ? '#16a34a' : 'var(--color-suave)' }">
                                    {{ r.activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </p>
                            <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.curp }}</p>
                            <p class="mt-1 text-sm">{{ r.cargo ?? '—' }}</p>
                        </div>
                        <button v-if="!r.activo" type="button" class="shrink-0 text-sm text-red-600 hover:text-red-700" @click="eliminar(r)">Eliminar</button>
                    </div>

                    <div class="mt-3 overflow-x-auto border-t pt-3" :style="{ borderColor: 'var(--color-borde)' }">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left" :style="{ color: 'var(--color-suave)' }">
                                    <th class="py-1 pr-3 font-medium">Serie</th>
                                    <th class="py-1 pr-3 font-medium">Vigencia</th>
                                    <th class="py-1 pr-3 font-medium">Registrado</th>
                                    <th class="py-1 pr-3 font-medium">Estado</th>
                                    <th class="py-1 pr-3 font-medium">.cer / .key</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in r.certificados" :key="c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                    <td class="py-1.5 pr-3 font-mono">{{ c.serie }}</td>
                                    <td class="py-1.5 pr-3">{{ c.vigencia_inicio }} – {{ c.vigencia_fin }}</td>
                                    <td class="py-1.5 pr-3">{{ c.registrado }}</td>
                                    <td class="py-1.5 pr-3">{{ c.vigente ? 'Vigente' : 'Anterior' }}</td>
                                    <td class="py-1.5 pr-3">{{ c.tiene_cer_guardado ? '.cer' : '—' }} / {{ c.tiene_key ? '.key' : '—' }}</td>
                                </tr>
                                <tr v-if="!r.certificados.length">
                                    <td colspan="5" class="py-2" :style="{ color: 'var(--color-suave)' }">Sin certificados registrados.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </template>
    </AppLayout>
</template>
