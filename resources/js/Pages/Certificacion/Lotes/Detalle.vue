<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import SaldoDeEmision from '@/Components/SaldoDeEmision.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

interface Lote {
    id: number;
    folio: string;
    nombre: string | null;
    tipo: string;
    tipo_label: string;
    estado: string;
    estado_label: string;
    estado_color: string;
    total: number;
    certificados: number;
    responsable: string | null;
    cerrado_en: string | null;
    firmado_en: string | null;
    creado_en: string | null;
}

interface Alumno {
    id: number;
    matricula_oferta_id: number;
    matricula: string | null;
    alumno: string;
    curp: string | null;
    programa_academico: string | null;
    plan: string | null;
    campus: string | null;
    estado: string;
    folio: string | null;
    error_mensaje: string | null;
    fecha_certificacion: string | null;
    xml_url: string | null;
    cadena_url: string | null;
}

interface Firma {
    responsable: string | null;
    tiene_responsable: boolean;
    tiene_cer: boolean;
    tiene_key: boolean;
    serie: string | null;
    vigencia_fin: string | null;
    dias_restantes: number | null;
    vencido: boolean;
}

interface Candidato {
    matricula_oferta_id: number;
    matricula: string | null;
    alumno: string;
    curp: string | null;
    programa_academico: string | null;
    plan: string | null;
    campus: string | null;
}

const props = defineProps<{
    lote: Lote;
    alumnos: Alumno[];
    firma: Firma;
    saldo: {
        modalidad: string;
        etiqueta: string;
        creditos: number;
        cuenta_creditos: boolean;
        explicacion: string;
    };
}>();

/** Lo que este lote gastaría al firmarse: lo que aún no tiene XML. */
const porEmitir = computed(() => props.alumnos.filter((a) => !a.xml_url).length);

const esBorrador = computed(() => props.lote.estado === 'borrador');
const enEsperaFirma = computed(() => props.lote.estado === 'en_espera_firma');
const firmado = computed(() => props.lote.estado === 'firmado');

// Badges con tinte del color (color-mix sobre transparente): mismo criterio que
// el resto del sistema —p. ej. las insignias de Ciclos—, así funcionan en claro
// y oscuro sin utilidades de color sueltas.
const estilosBadge: Record<string, { backgroundColor: string; color: string }> = {
    gris: { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' },
    ambar: { backgroundColor: 'color-mix(in srgb, #d97706 18%, transparent)', color: '#b45309' },
    verde: { backgroundColor: 'color-mix(in srgb, #16a34a 18%, transparent)', color: '#15803d' },
    rojo: { backgroundColor: 'color-mix(in srgb, #dc2626 18%, transparent)', color: '#b91c1c' },
};

function badgeAlumno(estado: string): { backgroundColor: string; color: string } {
    return { pendiente: estilosBadge.gris, certificado: estilosBadge.verde, error: estilosBadge.rojo }[estado] ?? estilosBadge.gris;
}

function etiquetaAlumno(estado: string): string {
    return { pendiente: 'Pendiente', certificado: 'Certificado', error: 'Error' }[estado] ?? estado;
}

// ── Buscador de candidatos ────────────────────────────────────────────────
const buscando = ref(false);
const busqueda = ref('');
const resultados = ref<Candidato[]>([]);
const seleccion = ref<number[]>([]);
let temporizador: ReturnType<typeof setTimeout> | null = null;

function buscar(): void {
    if (temporizador) clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get('/certificacion/lotes/candidatos', { params: { q: busqueda.value, tipo: props.lote.tipo } });
            resultados.value = data.resultados;
        } finally {
            buscando.value = false;
        }
    }, 300);
}

const yaEnLote = computed(() => new Set(props.alumnos.map((a) => a.matricula_oferta_id)));

function alternar(id: number): void {
    const i = seleccion.value.indexOf(id);
    if (i === -1) seleccion.value.push(id);
    else seleccion.value.splice(i, 1);
}

const agregando = ref(false);

function agregarSeleccionados(): void {
    if (seleccion.value.length === 0) return;
    agregando.value = true;
    router.post(`/certificacion/lotes/${props.lote.id}/alumnos`, { matricula_oferta_ids: seleccion.value }, {
        preserveScroll: true,
        onFinish: () => {
            agregando.value = false;
            seleccion.value = [];
            resultados.value = [];
            busqueda.value = '';
        },
    });
}

function quitar(alumno: Alumno): void {
    router.delete(`/certificacion/lotes/${props.lote.id}/alumnos/${alumno.id}`, { preserveScroll: true });
}

/**
 * Rehacer el certificado de un alumno que salió mal.
 *
 * Se avisa que no cuesta otro crédito porque es justo lo que frena a quien
 * detecta el error: el miedo a pagar dos veces por el mismo trámite.
 */
function rehacer(alumno: Alumno): void {
    if (!confirm(
        `Se borrará el XML sellado de ${alumno.alumno} para volver a generarlo al firmar el lote.\n\n`
        + 'No se cobra otro crédito: es el mismo alumno y el mismo plan.\n\n¿Continuar?',
    )) return;

    router.post(`/certificacion/lotes/${props.lote.id}/alumnos/${alumno.id}/regenerar`, {}, { preserveScroll: true });
}

// ── Ciclo de vida ─────────────────────────────────────────────────────────
function cerrar(): void {
    if (!confirm('Al cerrar el lote ya no podrás agregar ni quitar alumnos. ¿Continuar?')) return;
    router.put(`/certificacion/lotes/${props.lote.id}/cerrar`, {}, { preserveScroll: true });
}

function reabrir(): void {
    router.put(`/certificacion/lotes/${props.lote.id}/reabrir`, {}, { preserveScroll: true });
}

// ── Firma ─────────────────────────────────────────────────────────────────
const mostrarFirma = ref(false);
const nombreCer = ref<string | null>(null);
const nombreKey = ref<string | null>(null);
const formFirma = useForm<{ password: string; certificado: File | null; llave: File | null }>({
    password: '',
    certificado: null,
    llave: null,
});

function abrirFirma(): void {
    if (!props.firma.tiene_responsable) {
        toast.error('No hay un responsable de certificación activo. Regístralo en Configuración → Responsables.');
        return;
    }
    if (props.firma.vencido) {
        toast.error(`El certificado del responsable venció el ${props.firma.vigencia_fin}. Actualízalo en Configuración → Responsables antes de firmar.`);
        return;
    }
    mostrarFirma.value = true;
}

/** Al cargar el .cer: lo adjunta y avisa si coincide (o no) con el registrado. */
async function alCer(file: File | null): Promise<void> {
    formFirma.certificado = file;
    nombreCer.value = file?.name ?? null;
    if (!file) return;

    const fd = new FormData();
    fd.append('certificado', file);
    try {
        const { data } = await axios.post('/certificacion/lotes/verificar-certificado', fd);
        if (data.coincide) {
            toast.success(`El certificado coincide con el registrado (serie ${data.serie}).`);
        } else {
            toast.warning(`Ese .cer NO es el certificado registrado del responsable (subiste la serie ${data.serie}, se esperaba ${data.serie_esperada}).`);
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'No se pudo leer el certificado.');
    }
}

function alKey(file: File | null): void {
    formFirma.llave = file;
    nombreKey.value = file?.name ?? null;
}

function cerrarFirma(): void {
    mostrarFirma.value = false;
    formFirma.reset();
    nombreCer.value = null;
    nombreKey.value = null;
}

function firmar(): void {
    formFirma.post(`/certificacion/lotes/${props.lote.id}/firmar`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: cerrarFirma,
    });
}

// Errores de validación del DEC al intentar firmar: se muestran TODOS en un
// alert que permanece hasta que el usuario lo cierra (no es un toast).
const page = usePage();
const erroresFirma = ref<string[]>([]);
watch(
    () => (page.props.flash as any)?.errores_firma as string[] | null,
    (e) => { if (e && e.length) erroresFirma.value = e; },
    { immediate: true },
);
</script>

<template>
    <Head :title="`Lote ${lote.folio}`" />

    <AppLayout :titulo="`Lote ${lote.folio}`">

        <!-- Errores de validación al firmar: persistente hasta cerrarlo. -->
        <div
            v-if="erroresFirma.length"
            class="mb-6 rounded-lg border border-l-4 p-4"
            :style="{ borderColor: '#dc2626', backgroundColor: 'color-mix(in srgb, #dc2626 7%, transparent)' }"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-2">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-red-700">
                            No se firmó el lote: corrige {{ erroresFirma.length }} problema(s) antes de continuar.
                        </p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm" :style="{ color: 'var(--color-contenido)' }">
                            <li v-for="(e, i) in erroresFirma" :key="i">{{ e }}</li>
                        </ul>
                    </div>
                </div>
                <button type="button" class="shrink-0 rounded p-1 text-red-600 hover:bg-red-600/10" title="Cerrar" @click="erroresFirma = []">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>

        <!-- Encabezado del lote -->
        <section class="tarjeta mb-6 p-6">
            <BotonVolver href="/certificacion/lotes" texto="Lotes" class="mb-4" />

            <!-- Antes de la ficha del lote: si el saldo no alcanza, se ve sin buscarlo. -->
            <SaldoDeEmision :saldo="saldo" :por-emitir="porEmitir" class="mb-5" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="font-mono text-lg font-semibold">{{ lote.folio }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="estilosBadge[lote.estado_color]">
                            {{ lote.estado_label }}
                        </span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            {{ lote.tipo_label }}
                        </span>
                    </div>
                    <p v-if="lote.nombre" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">{{ lote.nombre }}</p>
                    <dl class="mt-3 flex flex-wrap gap-x-8 gap-y-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <div><dt class="inline">Alumnos:</dt> <dd class="inline font-medium" :style="{ color: 'var(--color-contenido)' }">{{ lote.total }}</dd></div>
                        <div v-if="lote.certificados > 0"><dt class="inline">Certificados:</dt> <dd class="inline font-medium" :style="{ color: 'var(--color-contenido)' }">{{ lote.certificados }}</dd></div>
                        <div v-if="lote.cerrado_en"><dt class="inline">Cerrado:</dt> <dd class="inline">{{ lote.cerrado_en }}</dd></div>
                        <div v-if="lote.firmado_en"><dt class="inline">Firmado:</dt> <dd class="inline">{{ lote.firmado_en }}</dd></div>
                        <div v-if="lote.responsable"><dt class="inline">Firmó:</dt> <dd class="inline">{{ lote.responsable }}</dd></div>
                    </dl>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <BotonPrincipal v-if="esBorrador" tipo="button" icono="ninguno" texto="Cerrar lote" @click="cerrar" />
                    <button
                        v-if="enEsperaFirma"
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="reabrir"
                    >
                        Reabrir
                    </button>
                    <BotonPrincipal v-if="enEsperaFirma" tipo="button" icono="ninguno" texto="Firmar lote" @click="abrirFirma" />
                    <a
                        v-if="firmado"
                        :href="`/certificacion/lotes/${lote.id}/xml-zip`"
                        class="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" />
                        </svg>
                        ZIP (XML + cadena)
                    </a>
                    <a
                        v-if="lote.total > 0"
                        :href="`/certificacion/lotes/${lote.id}/excel`"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-4 py-2 text-sm font-medium"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m18.375 0v1.5c0 .621-.504 1.125-1.125 1.125M3.375 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25" /></svg>
                        Excel
                    </a>
                </div>
            </div>
        </section>

        <!-- Panel de firma -->
        <section v-if="mostrarFirma" class="tarjeta mb-6 overflow-hidden">
            <!-- Encabezado con ícono -->
            <div
                class="flex items-start gap-3 border-b px-6 py-4"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 6%, transparent)' }"
            >
                <span
                    class="grid h-10 w-10 shrink-0 place-items-center rounded-full"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </span>
                <div>
                    <h3 class="text-base font-semibold">Firmar el lote</h3>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Se sellará cada alumno del lote y se generará su XML. La contraseña no se guarda.
                    </p>
                </div>
            </div>

            <form class="space-y-5 p-6" @submit.prevent="firmar">
                <!-- Quién firma + vigencia del certificado -->
                <div class="rounded-lg border px-4 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Firma</p>
                    <p class="mt-0.5 font-medium">{{ firma.responsable }}</p>
                    <p v-if="firma.serie" class="text-xs" :style="{ color: 'var(--color-suave)' }">Certificado {{ firma.serie }}</p>
                    <p v-if="firma.vigencia_fin" class="mt-1.5 flex items-center gap-1.5 text-xs">
                        <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: firma.vencido ? '#dc2626' : (firma.dias_restantes !== null && firma.dias_restantes <= 30 ? '#d97706' : '#16a34a') }" />
                        <span v-if="firma.vencido" class="font-medium text-red-600">Vencido el {{ firma.vigencia_fin }}</span>
                        <span v-else :style="{ color: 'var(--color-suave)' }">Vigente hasta {{ firma.vigencia_fin }} · {{ firma.dias_restantes }} día(s)</span>
                    </p>
                </div>

                <!-- Material: dropzones si no está guardado; aviso si ya lo está. -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Certificado (.cer)</label>
                        <ZonaArchivo
                            v-if="!firma.tiene_cer"
                            accept=".cer"
                            texto="Arrastra el .cer o haz clic para seleccionarlo"
                            :cargado="nombreCer"
                            @archivo="alCer"
                        />
                        <p v-else class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Ya guardado en su ficha
                        </p>
                        <p v-if="formFirma.errors.certificado" class="mt-1 text-xs text-red-600">{{ formFirma.errors.certificado }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Llave privada (.key)</label>
                        <ZonaArchivo
                            v-if="!firma.tiene_key"
                            accept=".key"
                            texto="Arrastra el .key o haz clic para seleccionarlo"
                            :cargado="nombreKey"
                            @archivo="alKey"
                        />
                        <p v-else class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Ya cargada en su ficha
                        </p>
                        <p v-if="formFirma.errors.llave" class="mt-1 text-xs text-red-600">{{ formFirma.errors.llave }}</p>
                    </div>
                </div>

                <div class="max-w-sm">
                    <CampoTexto
                        v-model="formFirma.password"
                        tipo="password"
                        etiqueta="Contraseña de la llave"
                        :error="formFirma.errors.password"
                    />
                </div>

                <div class="flex items-center gap-3 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <BotonPrincipal :procesando="formFirma.processing" texto="Firmar y sellar" cargando="Firmando…" />
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }"
                        @click="cerrarFirma">Cancelar</button>
                </div>
            </form>
        </section>

        <!-- Buscador de alumnos (solo borrador) -->
        <section v-if="esBorrador" class="tarjeta mb-6 p-6">
            <h3 class="text-base font-semibold">Agregar alumnos</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="lote.tipo === 'parcial'">Lote parcial: sólo aparecen alumnos con avance que <strong>aún no cierran</strong> su plan,</template>
                <template v-else>Lote total: sólo aparecen los que <strong>ya cerraron</strong> su plan,</template>
                sin certificado emitido y dentro de tus campus.
            </p>

            <input
                v-model="busqueda"
                type="search"
                placeholder="Buscar por matrícula, nombre o CURP…"
                class="mt-4 block w-full rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }"
                @input="buscar"
            />

            <p v-if="buscando" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">Buscando…</p>

            <div v-if="resultados.length" class="mt-4 space-y-1">
                <label
                    v-for="c in resultados"
                    :key="c.matricula_oferta_id"
                    class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm"
                    :class="yaEnLote.has(c.matricula_oferta_id) ? 'opacity-40' : ''"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <input
                        type="checkbox"
                        :checked="seleccion.includes(c.matricula_oferta_id)"
                        :disabled="yaEnLote.has(c.matricula_oferta_id)"
                        @change="alternar(c.matricula_oferta_id)"
                    />
                    <span class="font-mono">{{ c.matricula ?? '—' }}</span>
                    <span class="font-medium">{{ c.alumno }}</span>
                    <span :style="{ color: 'var(--color-suave)' }">{{ c.programa_academico }}<span v-if="c.campus"> · {{ c.campus }}</span></span>
                    <span v-if="yaEnLote.has(c.matricula_oferta_id)" class="ml-auto text-xs" :style="{ color: 'var(--color-suave)' }">Ya en el lote</span>
                </label>

                <div class="pt-2">
                    <BotonPrincipal
                        :procesando="agregando"
                        :deshabilitado="seleccion.length === 0"
                        :texto="`Agregar ${seleccion.length} seleccionado(s)`"
                        tipo="button"
                        @click="agregarSeleccionados"
                    />
                </div>
            </div>

            <p v-else-if="busqueda && !buscando" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin coincidencias elegibles.
            </p>
        </section>

        <!-- Alumnos del lote -->
        <div class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <th class="px-5 py-3 font-medium">Matrícula</th>
                            <th class="px-5 py-3 font-medium">Alumno</th>
                            <th class="px-5 py-3 font-medium">Programa académico</th>
                            <th class="px-5 py-3 font-medium">Campus</th>
                            <th class="px-5 py-3 font-medium">Estado</th>
                            <th class="px-5 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in alumnos"
                            :key="a.id"
                            class="border-b"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-5 py-3 font-mono">{{ a.matricula ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <div class="font-medium">{{ a.alumno }}</div>
                                <div class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.curp }}</div>
                            </td>
                            <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">
                                {{ a.programa_academico }}<span v-if="a.plan"> · {{ a.plan }}</span>
                            </td>
                            <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ a.campus ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="badgeAlumno(a.estado)">
                                    {{ etiquetaAlumno(a.estado) }}
                                </span>
                                <div v-if="a.error_mensaje" class="mt-1 text-xs text-red-600">{{ a.error_mensaje }}</div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <a v-if="a.xml_url" :href="a.xml_url" class="inline-flex items-center gap-1.5 text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" />
                                        </svg>
                                        XML
                                    </a>
                                    <a v-if="a.cadena_url" :href="a.cadena_url" class="text-sm font-medium" :style="{ color: 'var(--color-suave)' }" title="Cadena original (.txt)">Cadena</a>
                                    <!--
                                        Rehacer se ofrece cuando ya hay XML o cuando el sellado falló:
                                        son los dos momentos en que se descubre el error de captura.
                                    -->
                                    <button
                                        v-if="a.xml_url || a.estado === 'error'"
                                        type="button"
                                        class="text-sm font-medium"
                                        :style="{ color: 'var(--color-suave)' }"
                                        title="Borrar el XML y volver a generarlo (sin costo)"
                                        @click="rehacer(a)"
                                    >
                                        Rehacer
                                    </button>
                                    <BotonAccion v-if="esBorrador && !a.xml_url" variante="eliminar" solo-icono texto="Quitar del lote" @click="quitar(a)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="alumnos.length === 0">
                            <td colspan="6" class="px-5 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                                El lote está vacío. Agrega alumnos con el buscador de arriba.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
