<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

interface Lote {
    id: number;
    folio: string;
    nombre: string | null;
    etapa: string;
    estado: string;
    estado_label: string;
    estado_color: string;
    total: number;
    titulados: number;
    responsable: string | null;
    etapa_coincide: boolean;
    cerrado_en: string | null;
    firmado_en: string | null;
    enviado_en: string | null;
    creado_en: string | null;
}

interface Egresado {
    id: number;
    matricula: string | null;
    alumno: string;
    curp: string | null;
    carrera: string | null;
    plan: string | null;
    campus: string | null;
    estado: string;
    folio: string | null;
    error_mensaje: string | null;
    estado_ws: string | null;
    folio_proceso_ws: string | null;
    fecha_titulacion: string | null;
    xml_url: string | null;
    matricula_oferta_id?: number;
}

interface FirmanteInfo {
    orden: number;
    obligatorio: boolean;
    responsable: string | null;
    cargo: string | null;
    tiene_cer: boolean;
    tiene_key: boolean;
    serie: string | null;
    vigencia_fin: string | null;
    dias_restantes: number | null;
    vencido: boolean;
    sin_certificado: boolean;
}

interface Firma {
    tiene_responsable: boolean;
    firmantes: FirmanteInfo[];
}

interface Candidato {
    matricula_oferta_id: number;
    matricula: string | null;
    alumno: string;
    curp: string | null;
    carrera: string | null;
    plan: string | null;
    campus: string | null;
}

const props = defineProps<{ lote: Lote; egresados: Egresado[]; firma: Firma; etapaActiva: string; modoWs: string }>();

const esBorrador = computed(() => props.lote.estado === 'borrador');
const enEsperaFirma = computed(() => props.lote.estado === 'en_espera_firma');
const firmado = computed(() => props.lote.estado === 'firmado');
const enviado = computed(() => props.lote.estado === 'enviado');

const estilosBadge: Record<string, { backgroundColor: string; color: string }> = {
    gris: { backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' },
    ambar: { backgroundColor: 'color-mix(in srgb, #d97706 18%, transparent)', color: '#b45309' },
    azul: { backgroundColor: 'color-mix(in srgb, #2563eb 18%, transparent)', color: '#1d4ed8' },
    verde: { backgroundColor: 'color-mix(in srgb, #16a34a 18%, transparent)', color: '#15803d' },
    rojo: { backgroundColor: 'color-mix(in srgb, #dc2626 18%, transparent)', color: '#b91c1c' },
};

function badgeEgresado(estado: string): { backgroundColor: string; color: string } {
    return { pendiente: estilosBadge.gris, titulado: estilosBadge.verde, error: estilosBadge.rojo }[estado] ?? estilosBadge.gris;
}
function etiquetaEgresado(estado: string): string {
    return { pendiente: 'Pendiente', titulado: 'Titulado', error: 'Error' }[estado] ?? estado;
}
function estiloEtapa(etapa: string): { backgroundColor: string; color: string } {
    const c = etapa === 'produccion' ? '#16a34a' : '#d97706';
    return { backgroundColor: `color-mix(in srgb, ${c} 15%, transparent)`, color: c };
}

// ── Cambiar la etapa del lote (solo borrador) ─────────────────────────────
const editandoEtapa = ref(false);
const nuevaEtapa = ref(props.lote.etapa);
function guardarEtapa(): void {
    router.put(`/titulacion/lotes/${props.lote.id}/etapa`, { etapa: nuevaEtapa.value }, {
        preserveScroll: true,
        onSuccess: () => { editandoEtapa.value = false; },
    });
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
            const { data } = await axios.get('/titulacion/lotes/candidatos', { params: { q: busqueda.value } });
            resultados.value = data.resultados;
        } finally {
            buscando.value = false;
        }
    }, 300);
}

const yaEnLote = computed(() => new Set(props.egresados.map((a) => a.matricula_oferta_id)));

function alternar(id: number): void {
    const i = seleccion.value.indexOf(id);
    if (i === -1) seleccion.value.push(id);
    else seleccion.value.splice(i, 1);
}

const agregando = ref(false);
function agregarSeleccionados(): void {
    if (seleccion.value.length === 0) return;
    agregando.value = true;
    router.post(`/titulacion/lotes/${props.lote.id}/egresados`, { matricula_oferta_ids: seleccion.value }, {
        preserveScroll: true,
        onFinish: () => {
            agregando.value = false;
            seleccion.value = [];
            resultados.value = [];
            busqueda.value = '';
        },
    });
}

function quitar(e: Egresado): void {
    router.delete(`/titulacion/lotes/${props.lote.id}/egresados/${e.id}`, { preserveScroll: true });
}

// ── Ciclo de vida ─────────────────────────────────────────────────────────
function cerrar(): void {
    if (!confirm('Al cerrar el lote ya no podrás agregar ni quitar egresados ni cambiar su etapa. ¿Continuar?')) return;
    router.put(`/titulacion/lotes/${props.lote.id}/cerrar`, {}, { preserveScroll: true });
}
function reabrir(): void {
    router.put(`/titulacion/lotes/${props.lote.id}/reabrir`, {}, { preserveScroll: true });
}
function enviar(): void {
    if (!confirm(`Se enviarán los títulos al web service de la SEP en etapa «${props.lote.etapa}». ¿Continuar?`)) return;
    router.post(`/titulacion/lotes/${props.lote.id}/enviar`, {}, { preserveScroll: true });
}

// ── Firma ─────────────────────────────────────────────────────────────────
const mostrarFirma = ref(false);
const nombreCer = ref<string | null>(null);
const nombreKey = ref<string | null>(null);
const formFirma = useForm<{ password: string; password_2: string; certificado: File | null; llave: File | null }>({
    password: '', password_2: '', certificado: null, llave: null,
});

const firmante1 = computed<FirmanteInfo | null>(() => props.firma.firmantes[0] ?? null);
const firmante2 = computed<FirmanteInfo | null>(() => props.firma.firmantes[1] ?? null);

function abrirFirma(): void {
    if (!props.firma.tiene_responsable) {
        toast.error('No hay un responsable de titulación activo. Regístralo en Configuración → Responsables.');
        return;
    }
    if (firmante1.value?.vencido) {
        toast.error(`El certificado de ${firmante1.value.responsable} venció el ${firmante1.value.vigencia_fin}. Actualízalo antes de firmar.`);
        return;
    }
    if (!props.lote.etapa_coincide) {
        toast.error(`El lote es de «${props.lote.etapa}» pero la etapa activa es «${props.etapaActiva}». Ajusta la etapa antes de firmar.`);
        return;
    }
    mostrarFirma.value = true;
}

async function alCer(file: File | null): Promise<void> {
    formFirma.certificado = file;
    nombreCer.value = file?.name ?? null;
    if (!file) return;
    const fd = new FormData();
    fd.append('certificado', file);
    try {
        const { data } = await axios.post('/titulacion/lotes/verificar-certificado', fd);
        if (data.coincide) toast.success(`El certificado coincide con el registrado (serie ${data.serie}).`);
        else toast.warning(`Ese .cer NO es el registrado (subiste ${data.serie}, se esperaba ${data.serie_esperada}).`);
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
    formFirma.post(`/titulacion/lotes/${props.lote.id}/firmar`, {
        preserveScroll: true, forceFormData: true, onSuccess: cerrarFirma,
    });
}

// Errores de validación del título al intentar firmar: persisten hasta cerrarlos.
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
        <div class="mb-4">
            <Link href="/titulacion/lotes" class="text-sm" :style="{ color: 'var(--color-suave)' }">← Volver a lotes</Link>
        </div>

        <!-- Errores de validación al firmar -->
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
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="font-mono text-lg font-semibold">{{ lote.folio }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="estilosBadge[lote.estado_color]">{{ lote.estado_label }}</span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium capitalize" :style="estiloEtapa(lote.etapa)">Etapa: {{ lote.etapa }}</span>
                        <button v-if="esBorrador && !editandoEtapa" type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="editandoEtapa = true; nuevaEtapa = lote.etapa">
                            cambiar
                        </button>
                    </div>

                    <!-- Editor de etapa (solo borrador) -->
                    <div v-if="editandoEtapa" class="mt-3 flex flex-wrap items-center gap-2">
                        <div class="flex rounded-lg border p-0.5" :style="{ borderColor: 'var(--color-borde)' }">
                            <button
                                v-for="op in [{ v: 'pruebas', t: 'Pruebas' }, { v: 'produccion', t: 'Producción' }]"
                                :key="op.v" type="button"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                :style="nuevaEtapa === op.v ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-suave)' }"
                                @click="nuevaEtapa = op.v"
                            >{{ op.t }}</button>
                        </div>
                        <BotonPrincipal tipo="button" icono="guardar" texto="Guardar etapa" @click="guardarEtapa" />
                        <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="editandoEtapa = false">Cancelar</button>
                    </div>

                    <p v-if="!lote.etapa_coincide" class="mt-2 text-xs font-medium" :style="{ color: '#dc2626' }">
                        ⚠ La etapa del lote ({{ lote.etapa }}) no coincide con la activa ({{ etapaActiva }}). No se podrá firmar ni enviar hasta que coincidan.
                    </p>
                    <p v-if="lote.nombre" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">{{ lote.nombre }}</p>
                    <dl class="mt-3 flex flex-wrap gap-x-8 gap-y-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <div><dt class="inline">Egresados:</dt> <dd class="inline font-medium" :style="{ color: 'var(--color-contenido)' }">{{ lote.total }}</dd></div>
                        <div v-if="lote.titulados > 0"><dt class="inline">Titulados:</dt> <dd class="inline font-medium" :style="{ color: 'var(--color-contenido)' }">{{ lote.titulados }}</dd></div>
                        <div v-if="lote.cerrado_en"><dt class="inline">Cerrado:</dt> <dd class="inline">{{ lote.cerrado_en }}</dd></div>
                        <div v-if="lote.firmado_en"><dt class="inline">Firmado:</dt> <dd class="inline">{{ lote.firmado_en }}</dd></div>
                        <div v-if="lote.enviado_en"><dt class="inline">Enviado:</dt> <dd class="inline">{{ lote.enviado_en }}</dd></div>
                        <div v-if="lote.responsable"><dt class="inline">Firmó:</dt> <dd class="inline">{{ lote.responsable }}</dd></div>
                    </dl>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <BotonPrincipal v-if="esBorrador" tipo="button" icono="ninguno" texto="Cerrar lote" @click="cerrar" />
                    <button v-if="enEsperaFirma" type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="reabrir">Reabrir</button>
                    <BotonPrincipal v-if="enEsperaFirma" tipo="button" icono="ninguno" texto="Firmar lote" @click="abrirFirma" />
                    <BotonPrincipal
                        v-if="firmado"
                        tipo="button" icono="ninguno"
                        :texto="modoWs === 'off' ? 'Envío deshabilitado' : 'Enviar al WS'"
                        :deshabilitado="modoWs === 'off' || !lote.etapa_coincide"
                        @click="enviar"
                    />
                    <a
                        v-if="firmado || enviado"
                        :href="`/titulacion/lotes/${lote.id}/xml-zip`"
                        class="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" />
                        </svg>
                        Descargar XML (ZIP)
                    </a>
                </div>
            </div>
            <p v-if="firmado && modoWs === 'fake'" class="mt-3 text-xs" :style="{ color: '#d97706' }">
                El web service está en modo simulado (fake): el envío no llega a la SEP real.
            </p>
        </section>

        <!-- Panel de firma -->
        <section v-if="mostrarFirma" class="tarjeta mb-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 6%, transparent)' }">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </span>
                <div>
                    <h3 class="text-base font-semibold">Firmar el lote</h3>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Se sellará cada título del lote y se generará su XML. La contraseña no se guarda.
                    </p>
                </div>
            </div>

            <form class="space-y-5 p-6" @submit.prevent="firmar">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todos los firmantes sellan el mismo documento. El firmante 1 es obligatorio; si hay un
                    segundo responsable, también debe firmar.
                </p>

                <!-- Firmante 1 (obligatorio) -->
                <div v-if="firmante1" class="rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }">Firmante 1 · obligatorio</span>
                            <p class="mt-1.5 font-medium">{{ firmante1.responsable }} <span v-if="firmante1.cargo" class="text-xs" :style="{ color: 'var(--color-suave)' }">· {{ firmante1.cargo }}</span></p>
                        </div>
                        <p v-if="firmante1.vigencia_fin" class="text-right text-xs">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: firmante1.vencido ? '#dc2626' : (firmante1.dias_restantes !== null && firmante1.dias_restantes <= 30 ? '#d97706' : '#16a34a') }" />
                                <span v-if="firmante1.vencido" class="font-medium text-red-600">Vencido</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">Vigente a {{ firmante1.vigencia_fin }}</span>
                            </span>
                            <span v-if="firmante1.serie" class="block" :style="{ color: 'var(--color-suave)' }">Cert. {{ firmante1.serie }}</span>
                        </p>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Certificado (.cer)</label>
                            <ZonaArchivo v-if="!firmante1.tiene_cer" accept=".cer" texto="Arrastra el .cer o haz clic" :cargado="nombreCer" @archivo="alCer" />
                            <p v-else class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                                <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                Ya guardado en su ficha
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Llave privada (.key)</label>
                            <ZonaArchivo v-if="!firmante1.tiene_key" accept=".key" texto="Arrastra el .key o haz clic" :cargado="nombreKey" @archivo="alKey" />
                            <p v-else class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                                <svg class="h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                Ya cargada en su ficha
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 max-w-sm">
                        <CampoTexto v-model="formFirma.password" tipo="password" etiqueta="Contraseña de la llave" :error="formFirma.errors.password" />
                    </div>
                </div>

                <!-- Firmante 2 (opcional): usa el material de su ficha -->
                <div v-if="firmante2" class="rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }">Firmante 2 · segundo responsable</span>
                            <p class="mt-1.5 font-medium">{{ firmante2.responsable }} <span v-if="firmante2.cargo" class="text-xs" :style="{ color: 'var(--color-suave)' }">· {{ firmante2.cargo }}</span></p>
                        </div>
                        <p v-if="firmante2.vigencia_fin" class="text-right text-xs">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: firmante2.vencido ? '#dc2626' : (firmante2.dias_restantes !== null && firmante2.dias_restantes <= 30 ? '#d97706' : '#16a34a') }" />
                                <span v-if="firmante2.vencido" class="font-medium text-red-600">Vencido</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">Vigente a {{ firmante2.vigencia_fin }}</span>
                            </span>
                            <span v-if="firmante2.serie" class="block" :style="{ color: 'var(--color-suave)' }">Cert. {{ firmante2.serie }}</span>
                        </p>
                    </div>
                    <p v-if="firmante2.sin_certificado || !firmante2.tiene_cer || !firmante2.tiene_key" class="mt-3 text-xs" :style="{ color: '#dc2626' }">
                        Su .cer/.key deben estar cargados en su ficha (Configuración → Responsables) para poder firmar.
                    </p>
                    <div class="mt-4 max-w-sm">
                        <CampoTexto v-model="formFirma.password_2" tipo="password" etiqueta="Contraseña de su llave" :error="formFirma.errors.password_2" />
                    </div>
                </div>

                <div class="flex items-center gap-3 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <BotonPrincipal :procesando="formFirma.processing" texto="Firmar y sellar" cargando="Firmando…" />
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="cerrarFirma">Cancelar</button>
                </div>
            </form>
        </section>

        <!-- Buscador de egresados (solo borrador) -->
        <section v-if="esBorrador" class="tarjeta mb-6 p-6">
            <h3 class="text-base font-semibold">Agregar egresados</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sólo aparecen egresados que <strong>ya cerraron</strong> su plan, sin título en otro lote y dentro de tus campus.
            </p>

            <input
                v-model="busqueda" type="search" placeholder="Buscar por matrícula, nombre o CURP…"
                class="mt-4 block w-full rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }"
                @input="buscar"
            />

            <p v-if="buscando" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">Buscando…</p>

            <div v-if="resultados.length" class="mt-4 space-y-1">
                <label
                    v-for="c in resultados" :key="c.matricula_oferta_id"
                    class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm"
                    :class="yaEnLote.has(c.matricula_oferta_id) ? 'opacity-40' : ''"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <input type="checkbox" :checked="seleccion.includes(c.matricula_oferta_id)" :disabled="yaEnLote.has(c.matricula_oferta_id)" @change="alternar(c.matricula_oferta_id)" />
                    <span class="font-mono">{{ c.matricula ?? '—' }}</span>
                    <span class="font-medium">{{ c.alumno }}</span>
                    <span :style="{ color: 'var(--color-suave)' }">{{ c.carrera }}<span v-if="c.campus"> · {{ c.campus }}</span></span>
                    <span v-if="yaEnLote.has(c.matricula_oferta_id)" class="ml-auto text-xs" :style="{ color: 'var(--color-suave)' }">Ya en el lote</span>
                </label>

                <div class="pt-2">
                    <BotonPrincipal :procesando="agregando" :deshabilitado="seleccion.length === 0" :texto="`Agregar ${seleccion.length} seleccionado(s)`" tipo="button" @click="agregarSeleccionados" />
                </div>
            </div>

            <p v-else-if="busqueda && !buscando" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">Sin coincidencias elegibles.</p>
        </section>

        <!-- Egresados del lote -->
        <div class="tarjeta overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <th class="px-5 py-3 font-medium">Matrícula</th>
                        <th class="px-5 py-3 font-medium">Egresado</th>
                        <th class="px-5 py-3 font-medium">Carrera</th>
                        <th class="px-5 py-3 font-medium">Estado</th>
                        <th class="px-5 py-3 font-medium">WS</th>
                        <th class="px-5 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in egresados" :key="e.id" class="border-b" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-5 py-3 font-mono">{{ e.matricula ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <div class="font-medium">{{ e.alumno }}</div>
                            <div class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ e.curp }}</div>
                        </td>
                        <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ e.carrera }}<span v-if="e.plan"> · {{ e.plan }}</span></td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :style="badgeEgresado(e.estado)">{{ etiquetaEgresado(e.estado) }}</span>
                            <div v-if="e.error_mensaje" class="mt-1 text-xs text-red-600">{{ e.error_mensaje }}</div>
                        </td>
                        <td class="px-5 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="e.estado_ws" :style="{ color: e.estado_ws === 'aceptado' ? '#15803d' : '#b91c1c' }">{{ e.estado_ws }}</span>
                            <span v-if="e.folio_proceso_ws" class="block font-mono">{{ e.folio_proceso_ws }}</span>
                            <span v-if="!e.estado_ws">—</span>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a v-if="e.xml_url" :href="e.xml_url" class="inline-flex items-center gap-1.5 text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-6L12 15m0 0 4.5-4.5M12 15V3" />
                                    </svg>
                                    XML
                                </a>
                                <BotonAccion v-else-if="esBorrador" variante="eliminar" solo-icono texto="Quitar del lote" @click="quitar(e)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="egresados.length === 0">
                        <td colspan="6" class="px-5 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                            El lote está vacío. Agrega egresados con el buscador de arriba.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
