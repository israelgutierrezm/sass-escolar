<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Lote {
    id: number;
    folio: string;
    nombre: string | null;
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
    carrera: string | null;
    plan: string | null;
    campus: string | null;
    estado: string;
    folio: string | null;
    error_mensaje: string | null;
    fecha_certificacion: string | null;
    xml_url: string | null;
}

interface Firma {
    responsable: string | null;
    tiene_responsable: boolean;
    tiene_cer: boolean;
    tiene_key: boolean;
    serie: string | null;
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

const props = defineProps<{ lote: Lote; alumnos: Alumno[]; firma: Firma }>();

const esBorrador = computed(() => props.lote.estado === 'borrador');
const enEsperaFirma = computed(() => props.lote.estado === 'en_espera_firma');
const firmado = computed(() => props.lote.estado === 'firmado');

const clasesBadge: Record<string, string> = {
    gris: 'bg-gray-100 text-gray-700 dark:bg-gray-700/40 dark:text-gray-200',
    ambar: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
    verde: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
    rojo: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300',
};

function badgeAlumno(estado: string): string {
    return { pendiente: clasesBadge.gris, certificado: clasesBadge.verde, error: clasesBadge.rojo }[estado] ?? clasesBadge.gris;
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
            const { data } = await axios.get('/certificacion/lotes/candidatos', { params: { q: busqueda.value } });
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

// ── Ciclo de vida ─────────────────────────────────────────────────────────
function cerrar(): void {
    if (!confirm('Al cerrar el lote ya no podrás agregar ni quitar alumnos. ¿Continuar?')) return;
    router.put(`/certificacion/lotes/${props.lote.id}/cerrar`, {}, { preserveScroll: true });
}

function reabrir(): void {
    router.put(`/certificacion/lotes/${props.lote.id}/reabrir`, {}, { preserveScroll: true });
}

function eliminar(): void {
    if (!confirm(`¿Eliminar el lote ${props.lote.folio}? Esta acción no se puede deshacer.`)) return;
    router.delete(`/certificacion/lotes/${props.lote.id}`);
}

// ── Firma ─────────────────────────────────────────────────────────────────
const mostrarFirma = ref(false);
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
    mostrarFirma.value = true;
}

function firmar(): void {
    formFirma.post(`/certificacion/lotes/${props.lote.id}/firmar`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            mostrarFirma.value = false;
            formFirma.reset();
        },
    });
}
</script>

<template>
    <Head :title="`Lote ${lote.folio}`" />

    <AppLayout :titulo="`Lote ${lote.folio}`">
        <div class="mb-4">
            <Link href="/certificacion/lotes" class="text-sm" :style="{ color: 'var(--color-suave)' }">← Volver a lotes</Link>
        </div>

        <!-- Encabezado del lote -->
        <section class="tarjeta mb-6 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="font-mono text-lg font-semibold">{{ lote.folio }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="clasesBadge[lote.estado_color]">
                            {{ lote.estado_label }}
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

                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="esBorrador"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        @click="cerrar"
                    >
                        Cerrar lote
                    </button>
                    <button
                        v-if="enEsperaFirma"
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="reabrir"
                    >
                        Reabrir
                    </button>
                    <button
                        v-if="enEsperaFirma"
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        @click="abrirFirma"
                    >
                        Firmar lote
                    </button>
                    <button
                        v-if="!firmado"
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm text-red-600 hover:text-red-700"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="eliminar"
                    >
                        Eliminar
                    </button>
                </div>
            </div>
        </section>

        <!-- Modal de firma -->
        <section v-if="mostrarFirma" class="tarjeta mb-6 border-2 p-6" :style="{ borderColor: 'var(--color-acento)' }">
            <h3 class="text-base font-semibold">Firmar el lote</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Firmará <strong>{{ firma.responsable }}</strong><span v-if="firma.serie"> · certificado {{ firma.serie }}</span>.
                Se sellará cada alumno del lote y se generará su XML.
            </p>

            <form class="mt-4 space-y-4" @submit.prevent="firmar">
                <div v-if="!firma.tiene_cer">
                    <label class="block text-sm font-medium">Certificado (.cer)</label>
                    <input type="file" accept=".cer" class="mt-1 block w-full text-sm"
                        @change="formFirma.certificado = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <p v-if="formFirma.errors.certificado" class="mt-1 text-xs text-red-600">{{ formFirma.errors.certificado }}</p>
                </div>
                <div v-if="!firma.tiene_key">
                    <label class="block text-sm font-medium">Llave privada (.key)</label>
                    <input type="file" accept=".key" class="mt-1 block w-full text-sm"
                        @change="formFirma.llave = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <p v-if="formFirma.errors.llave" class="mt-1 text-xs text-red-600">{{ formFirma.errors.llave }}</p>
                </div>
                <div class="max-w-sm">
                    <label class="block text-sm font-medium">Contraseña de la llave</label>
                    <input v-model="formFirma.password" type="password" autocomplete="off"
                        class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }" />
                    <p v-if="formFirma.errors.password" class="mt-1 text-xs text-red-600">{{ formFirma.errors.password }}</p>
                </div>

                <div class="flex items-center gap-3">
                    <BotonPrincipal :procesando="formFirma.processing" texto="Firmar y sellar" cargando="Firmando…" />
                    <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }"
                        @click="mostrarFirma = false">Cancelar</button>
                </div>
            </form>
        </section>

        <!-- Buscador de alumnos (solo borrador) -->
        <section v-if="esBorrador" class="tarjeta mb-6 p-6">
            <h3 class="text-base font-semibold">Agregar alumnos</h3>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sólo aparecen los que ya cerraron su plan, sin certificado emitido y dentro de tus campus.
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
                    <span :style="{ color: 'var(--color-suave)' }">{{ c.carrera }}<span v-if="c.campus"> · {{ c.campus }}</span></span>
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
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <th class="px-5 py-3 font-medium">Matrícula</th>
                        <th class="px-5 py-3 font-medium">Alumno</th>
                        <th class="px-5 py-3 font-medium">Carrera</th>
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
                            {{ a.carrera }}<span v-if="a.plan"> · {{ a.plan }}</span>
                        </td>
                        <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ a.campus ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="badgeAlumno(a.estado)">
                                {{ etiquetaAlumno(a.estado) }}
                            </span>
                            <div v-if="a.error_mensaje" class="mt-1 text-xs text-red-600">{{ a.error_mensaje }}</div>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <a v-if="a.xml_url" :href="a.xml_url" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">
                                Descargar XML
                            </a>
                            <button
                                v-else-if="esBorrador"
                                type="button"
                                class="text-sm text-red-600 hover:text-red-700"
                                @click="quitar(a)"
                            >
                                Quitar
                            </button>
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
    </AppLayout>
</template>
