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
    titulo: string | null;
    activo: boolean;
    tiene_cer_guardado: boolean;
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

const cert = ref<DatosCert | null>(null);
const nombreArchivo = ref<string | null>(null);
const leyendo = ref(false);
const arrastrando = ref(false);
const entrada = ref<HTMLInputElement | null>(null);

const form = useForm<{ certificado: File | null; cargo_id: number | null; titulo_profesional_id: number | null; guardar_cer: boolean }>({
    certificado: null,
    cargo_id: null,
    titulo_profesional_id: null,
    guardar_cer: false,
});

const completo = computed(() => cert.value !== null && !!form.cargo_id && !!form.titulo_profesional_id);

async function procesar(archivo: File | null): Promise<void> {
    cert.value = null;
    nombreArchivo.value = null;
    form.certificado = archivo;

    if (!archivo) {
        return;
    }

    if (!archivo.name.toLowerCase().endsWith('.cer')) {
        form.certificado = null;
        toast.error('El archivo debe ser un certificado con extensión .cer');

        return;
    }

    leyendo.value = true;
    try {
        const datos = new FormData();
        datos.append('certificado', archivo);
        const { data } = await axios.post<DatosCert>(`${base.value}/leer-certificado`, datos);
        cert.value = data;
        nombreArchivo.value = archivo.name;
        toast.success(`Certificado leído: ${data.titular}`);
    } catch (e: any) {
        form.certificado = null;
        toast.error(e?.response?.data?.error ?? 'No se pudo leer el certificado.');
    } finally {
        leyendo.value = false;
    }
}

function alSoltar(evento: DragEvent): void {
    arrastrando.value = false;
    procesar(evento.dataTransfer?.files?.[0] ?? null);
}

function alCambiarInput(evento: Event): void {
    procesar((evento.target as HTMLInputElement).files?.[0] ?? null);
}

function limpiarCert(): void {
    cert.value = null;
    nombreArchivo.value = null;
    form.certificado = null;
    if (entrada.value) {
        entrada.value.value = '';
    }
}

function guardar(): void {
    form.post(base.value, {
        onSuccess: () => {
            form.reset();
            limpiarCert();
        },
    });
}

function desactivar(r: Responsable): void {
    if (!confirm(`¿Desactivar a ${r.nombre_completo}? Quedará en el historial y dejará de firmar.`)) {
        return;
    }
    router.put(`${base.value}/${r.id}/desactivar`, {}, { preserveScroll: true });
}

function eliminar(r: Responsable): void {
    if (!confirm(`¿Eliminar del historial a ${r.nombre_completo}?`)) {
        return;
    }
    router.delete(`${base.value}/${r.id}`, { preserveScroll: true });
}

const opcionesCargo = computed(() => props.cargos.map((c) => ({ valor: c.id, texto: c.nombre })));
const opcionesTitulo = computed(() => props.titulos.map((t) => ({ valor: t.id, texto: `${t.abreviatura} — ${t.descripcion}` })));
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

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div v-for="r in activos" :key="r.id" class="rounded-xl border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ r.titulo ? `${r.titulo} ` : '' }}{{ r.nombre_completo }}</p>
                            <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.curp }}</p>
                            <p class="mt-1 text-sm">{{ r.cargo ?? '—' }}</p>
                        </div>
                        <button type="button" class="shrink-0 text-sm text-red-600 hover:text-red-700" @click="desactivar(r)">Desactivar</button>
                    </div>
                    <dl class="mt-3 border-t pt-3 text-xs" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <div class="flex justify-between gap-2"><dt>Titular del .cer</dt><dd class="truncate text-right">{{ r.cer_titular }}</dd></div>
                        <div class="mt-1 flex justify-between gap-2"><dt>Número de serie</dt><dd class="font-mono">{{ r.cer_serial }}</dd></div>
                        <div class="mt-1 flex justify-between gap-2"><dt>Vigencia</dt><dd>{{ r.vigencia_inicio }} – {{ r.vigencia_fin }}</dd></div>
                        <div v-if="r.tiene_cer_guardado" class="mt-1 flex justify-between gap-2"><dt>.cer guardado</dt><dd>Sí</dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <!-- Alta: formulario estándar en una tarjeta -->
        <section v-if="puedeAgregar" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Agregar responsable</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Carga el certificado (<b>.cer</b>) del responsable; sus datos se leen del archivo y solo
                completas el título y el cargo. Todos los campos son obligatorios.
            </p>

            <form class="mt-5 space-y-5" @submit.prevent="guardar">
                <!-- Campo del certificado -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Certificado (.cer) <span class="text-red-500">*</span></label>
                    <input ref="entrada" type="file" accept=".cer" class="hidden" @change="alCambiarInput" />

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
                        @drop.prevent="alSoltar"
                    >
                        <span :style="{ color: 'var(--color-suave)' }">
                            <template v-if="leyendo">Leyendo certificado…</template>
                            <template v-else>Arrastra el <b>.cer</b> o <span :style="{ color: 'var(--color-acento)' }" class="font-medium">selecciónalo</span></template>
                        </span>
                    </div>

                    <div v-else class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <span class="flex min-w-0 items-center gap-2">
                            <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <span class="truncate">{{ nombreArchivo }}</span>
                        </span>
                        <button type="button" class="shrink-0 font-medium" :style="{ color: 'var(--color-acento)' }" @click="entrada?.click()">Cambiar</button>
                    </div>
                </div>

                <!-- Datos leídos del cert (solo lectura) + lo que se completa -->
                <div v-if="cert" class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto :model-value="cert.nombre" etiqueta="Nombre" deshabilitado />
                    <CampoTexto :model-value="cert.curp" etiqueta="CURP" mono deshabilitado />
                    <CampoTexto :model-value="cert.apellido_paterno" etiqueta="Apellido paterno" deshabilitado />
                    <CampoTexto :model-value="cert.apellido_materno || '—'" etiqueta="Apellido materno" deshabilitado />

                    <CampoSelect
                        v-model="form.titulo_profesional_id"
                        etiqueta="Título profesional"
                        requerido
                        vacio="Seleccione una opción"
                        :opciones="opcionesTitulo"
                        :error="form.errors.titulo_profesional_id"
                    />
                    <CampoSelect
                        v-model="form.cargo_id"
                        etiqueta="Cargo"
                        requerido
                        vacio="Seleccione una opción"
                        :opciones="opcionesCargo"
                        :error="form.errors.cargo_id"
                    />

                    <div class="sm:col-span-2 rounded-lg border p-3 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <label class="flex items-start gap-2">
                            <input v-model="form.guardar_cer" type="checkbox" class="mt-0.5 rounded" />
                            <span>
                                <span class="font-medium">Guardar mi .cer</span>
                                <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Se almacena el certificado para no volver a subirlo al firmar; solo pedirá el .key y la contraseña.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <BotonPrincipal :procesando="form.processing" texto="Guardar responsable" :deshabilitado="!completo" />
                </div>
            </form>
        </section>

        <section v-else class="tarjeta p-6 text-sm" :style="{ color: 'var(--color-suave)' }">
            Ya hay {{ maximo }} responsable(s) activo(s) para {{ tituloSeccion.toLowerCase() }}. Desactiva uno
            para poder agregar otro.
        </section>

        <!-- Historial (desactivados): se conservan para ligar sus firmas -->
        <section v-if="historial.length" class="tarjeta mt-6 p-6">
            <h2 class="text-base font-semibold">Historial</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Responsables desactivados. Se conservan porque los documentos que firmaron quedan ligados a ellos.
            </p>

            <ul class="mt-4 divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="r in historial" :key="r.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ r.titulo ? `${r.titulo} ` : '' }}{{ r.nombre_completo }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ r.cargo ?? '—' }} · serie {{ r.cer_serial }} · vigencia {{ r.vigencia_inicio }} – {{ r.vigencia_fin }}
                        </p>
                    </div>
                    <button type="button" class="shrink-0 text-sm text-red-600 hover:text-red-700" @click="eliminar(r)">Eliminar</button>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>

<style scoped>
.zona {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    cursor: pointer;
    border-radius: 0.5rem;
    border: 1.5px dashed var(--color-borde);
    padding: 1.1rem 1rem;
    font-size: 0.875rem;
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
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
