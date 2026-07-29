<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Responsable {
    id: number;
    nombre_completo: string;
    curp: string;
    cargo: string | null;
    titulo: string | null;
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
    responsables: Responsable[];
    cargos: { id: number; nombre: string }[];
    titulos: { id: number; abreviatura: string; descripcion: string }[];
}>();

const base = computed(() => `/${props.seccion}/configuracion/responsables`);
const puedeAgregar = computed(() => props.responsables.length < props.maximo);

// Datos leídos del .cer; null hasta que se carga uno válido. El formulario solo
// aparece (y se puede guardar) cuando hay certificado leído.
const cert = ref<DatosCert | null>(null);
const listo = computed(() => cert.value !== null);
const leyendo = ref(false);
const arrastrando = ref(false);
const entrada = ref<HTMLInputElement | null>(null);

const form = useForm<{ certificado: File | null; cargo_id: number | null; titulo_profesional_id: number | null }>({
    certificado: null,
    cargo_id: null,
    titulo_profesional_id: null,
});

async function procesar(archivo: File | null): Promise<void> {
    cert.value = null;
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

function eliminar(r: Responsable): void {
    if (!confirm(`¿Eliminar al responsable ${r.nombre_completo}?`)) {
        return;
    }
    router.delete(`${base.value}/${r.id}`, { preserveScroll: true });
}

const opcionesCargo = computed(() => props.cargos.map((c) => ({ valor: c.id, texto: c.nombre })));
const opcionesTitulo = computed(() => props.titulos.map((t) => ({ valor: t.id, texto: `${t.abreviatura} — ${t.descripcion}` })));

const datosCert = computed(() => [
    { etiqueta: 'Nombre', valor: cert.value?.nombre, mono: false },
    { etiqueta: 'Apellido paterno', valor: cert.value?.apellido_paterno, mono: false },
    { etiqueta: 'Apellido materno', valor: cert.value?.apellido_materno || '—', mono: false },
    { etiqueta: 'CURP', valor: cert.value?.curp, mono: true },
    { etiqueta: 'Número de serie', valor: cert.value?.serial, mono: true },
    { etiqueta: 'Vigencia', valor: `${cert.value?.vigencia_inicio} – ${cert.value?.vigencia_fin}`, mono: false },
]);
</script>

<template>
    <Head :title="`Responsables · ${tituloSeccion}`" />

    <AppLayout :titulo="`${tituloSeccion} · Responsables`">
        <!-- Responsables ya registrados -->
        <section v-if="responsables.length" class="tarjeta mb-6 p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Responsables registrados</h2>
                <span class="text-sm" :style="{ color: 'var(--color-suave)' }">{{ responsables.length }} de {{ maximo }}</span>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div v-for="r in responsables" :key="r.id" class="rounded-xl border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ r.titulo ? `${r.titulo} ` : '' }}{{ r.nombre_completo }}</p>
                            <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.curp }}</p>
                            <p class="mt-1 text-sm">{{ r.cargo ?? '—' }}</p>
                        </div>
                        <button type="button" class="shrink-0 text-sm text-red-600 hover:text-red-700" @click="eliminar(r)">Eliminar</button>
                    </div>
                    <dl class="mt-3 border-t pt-3 text-xs" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <div class="flex justify-between gap-2"><dt>Titular del .cer</dt><dd class="truncate text-right">{{ r.cer_titular }}</dd></div>
                        <div class="mt-1 flex justify-between gap-2"><dt>Número de serie</dt><dd class="font-mono">{{ r.cer_serial }}</dd></div>
                        <div class="mt-1 flex justify-between gap-2"><dt>Vigencia</dt><dd>{{ r.vigencia_inicio }} – {{ r.vigencia_fin }}</dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <!-- Alta (solo si aún hay cupo) -->
        <section v-if="puedeAgregar" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Agregar responsable</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Carga el certificado (<b>.cer</b>) del responsable. Sus datos se leen del archivo; solo
                completas el título y el cargo. Todos los campos son obligatorios.
            </p>

            <!-- Sin certificado: solo la zona de carga -->
            <div v-if="!listo" class="mt-5">
                <div
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
                    <input ref="entrada" type="file" accept=".cer" class="hidden" @change="alCambiarInput" />
                    <svg class="mx-auto h-8 w-8" :style="{ color: 'var(--color-acento)' }" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                    </svg>
                    <p class="mt-2 text-sm font-medium">
                        <span v-if="leyendo">Leyendo certificado…</span>
                        <span v-else>Arrastra el <b>.cer</b> aquí o haz clic para seleccionarlo</span>
                    </p>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">Solo el archivo .cer del responsable</p>
                </div>
            </div>

            <!-- Con certificado: datos leídos + lo que se completa -->
            <form v-else class="mt-5 space-y-5" @submit.prevent="guardar">
                <div class="rounded-xl border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Datos del certificado</p>
                            <p class="mt-0.5 truncate font-semibold">{{ cert!.titular }}</p>
                        </div>
                        <button type="button" class="shrink-0 text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="limpiarCert">
                            Cambiar .cer
                        </button>
                    </div>

                    <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="d in datosCert" :key="d.etiqueta">
                            <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ d.etiqueta }}</dt>
                            <dd class="mt-0.5 text-sm" :class="d.mono ? 'font-mono' : ''">{{ d.valor }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
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
                </div>

                <div class="flex justify-end">
                    <BotonPrincipal
                        :procesando="form.processing"
                        texto="Guardar responsable"
                        :deshabilitado="!form.titulo_profesional_id || !form.cargo_id"
                    />
                </div>
            </form>
        </section>

        <section v-else class="tarjeta p-6 text-sm" :style="{ color: 'var(--color-suave)' }">
            Ya se registró el máximo de responsables ({{ maximo }}) para {{ tituloSeccion.toLowerCase() }}.
            Elimina uno para poder registrar otro.
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
