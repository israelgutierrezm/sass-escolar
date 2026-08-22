<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

/**
 * Los documentos que la escuela le pide AL TUTOR, no a su hijo.
 *
 * Misma forma que «Mi expediente» del alumno y del docente, y por la misma
 * razón: lo primero es QUÉ FALTA. Nadie entra aquí a mirar lo que ya entregó;
 * se entra porque la escuela pidió la identificación, o porque venció el
 * comprobante de domicilio. Sin nada pendiente, la tarjeta de arriba no se
 * dibuja — un tablero que siempre dice algo deja de leerse.
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
    documentos: Documento[];
    tiposDocumento: TipoDocumento[];
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
 * El avance se mide contra los OBLIGATORIOS y no contra todo lo subido: quien
 * cargó dos constancias opcionales y no su identificación no va adelantado.
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

/* ── Subir y quitar ────────────────────────────────────────────────────── */

const formDoc = useForm({
    documento_id: null as number | null,
    archivo: null as File | null,
    descripcion: '',
    vigencia: '',
});

function subir(): void {
    formDoc.post('/mis-hijos/expediente/documentos', {
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

    router.delete(`/mis-hijos/expediente/documentos/${doc.id}`, { preserveScroll: true });
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
</script>

<template>
    <Head title="Mis documentos" />

    <AppLayout titulo="Mis documentos">
        <p class="mb-4 text-sm text-suave">
            Lo que la escuela te pide a ti como tutor. Los documentos de tus hijos
            se entregan por separado.
        </p>

        <!--
            La tarjeta de pendientes sólo cuando hay algo que hacer. Con cero
            faltantes diría «todo en orden» todos los días hasta que nadie la
            mire, que es justo cuando haría falta.
        -->
        <TarjetaSeccion
            v-if="pendientes > 0"
            titulo="Lo que falta"
            :descripcion="`${cubiertos} de ${obligatorios} obligatorios entregados (${avance} %)`"
            class="mb-4"
        >
            <ul class="space-y-2 text-sm">
                <li v-for="tipo in faltantes" :key="`falta-${tipo.id}`" class="flex items-center justify-between gap-3">
                    <span>{{ tipo.nombre }} — <span class="text-suave">sin entregar</span></span>
                    <button
                        type="button"
                        class="shrink-0 text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="subirEste(tipo.id)"
                    >
                        Subir
                    </button>
                </li>

                <li v-for="doc in rechazados" :key="`rech-${doc.id}`" class="flex items-center justify-between gap-3">
                    <span>
                        {{ doc.documento }} — <span class="font-medium text-red-600">rechazado</span>
                        <span v-if="doc.observaciones" class="text-suave"> · {{ doc.observaciones }}</span>
                    </span>
                    <button
                        v-if="doc.documento_id"
                        type="button"
                        class="shrink-0 text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="subirEste(doc.documento_id)"
                    >
                        Corregir
                    </button>
                </li>

                <li v-for="doc in vencidos" :key="`venc-${doc.id}`" class="flex items-center justify-between gap-3">
                    <span>{{ doc.documento }} — <span class="font-medium text-red-600">vencido</span></span>
                    <button
                        v-if="doc.documento_id"
                        type="button"
                        class="shrink-0 text-xs font-medium"
                        :style="{ color: 'var(--color-acento)' }"
                        @click="subirEste(doc.documento_id)"
                    >
                        Renovar
                    </button>
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Mis documentos" sin-relleno>
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
                            :href="`/mis-hijos/expediente/documentos/${doc.id}/descargar`"
                            class="text-sm"
                            :style="{ color: 'var(--color-acento)' }"
                        >
                            Descargar
                        </a>
                        <!--
                            Lo aceptado no se quita desde aquí: es la constancia
                            de un trámite cerrado. Para cambiarlo se vuelve a
                            subir, y eso lo devuelve a revisión.
                        -->
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
                            ayuda="PDF o imagen, máximo 8 MB."
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
