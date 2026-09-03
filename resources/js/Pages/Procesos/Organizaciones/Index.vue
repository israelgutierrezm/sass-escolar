<script setup lang="ts">
/**
 * El padrón de organizaciones receptoras.
 *
 * ── El padrón es INSTITUCIONAL ─────────────────────────────────────────────
 * No se acota por campus: una dependencia de gobierno no pertenece a un
 * plantel, y un coordinador que no la viera la daría de alta otra vez. Lo que
 * sí acota es el ALCANCE, y eso se decide al asignar. El filtro por campus está
 * para no leer un padrón entero, no como candado — y por eso incluye las que no
 * declaran alcance, que sirven en cualquier campus.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Organizacion {
    id: number;
    razon_social: string;
    nombre_comercial: string | null;
    rfc: string | null;
    sector: string | null;
    tipo: string | null;
    situacion: string | null;
    recibe: boolean;
    municipio: string | null;
    contactos: number;
    plazas: number;
    convenios_vigentes: number;
}

interface Opcion { id: number; nombre: string }

const props = defineProps<{
    organizaciones: { data: Organizacion[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: Record<string, unknown>;
    catalogos: {
        sectores: Opcion[];
        tipos: Opcion[];
        situaciones: (Opcion & { acepta_asignaciones: boolean })[];
        campus: Opcion[];
        programas: Opcion[];
        tiposProceso: Opcion[];
        entidades: Opcion[];
    };
    puedeEditar: boolean;
}>();

const busca = ref((props.filtros.busca as string) ?? '');
const situacionId = ref((props.filtros.situacion_id as number | null) ?? null);
const sectorId = ref((props.filtros.sector_id as number | null) ?? null);
const campusId = ref((props.filtros.campus_id as number | null) ?? null);

const creando = ref(false);
const errores = ref<Record<string, string>>({});
const procesando = ref(false);

const datos = ref<Record<string, unknown>>({});

const vacio = computed(() => props.organizaciones.data.length === 0);

/** La situación con la que nace una organización nueva: la que sí recibe. */
const situacionPorOmision = computed(
    () => props.catalogos.situaciones.find((s) => s.acepta_asignaciones)?.id ?? props.catalogos.situaciones[0]?.id ?? null,
);

function filtrar(): void {
    router.get('/procesos/organizaciones', {
        busca: busca.value || undefined,
        situacion_id: situacionId.value ?? undefined,
        sector_id: sectorId.value ?? undefined,
        campus_id: campusId.value ?? undefined,
    }, { preserveState: true, replace: true });
}

function abrirAlta(): void {
    errores.value = {};
    datos.value = {
        razon_social: '',
        nombre_comercial: '',
        rfc: '',
        sector_id: null,
        tipo_id: null,
        situacion_id: situacionPorOmision.value,
        calle: '',
        colonia: '',
        municipio: '',
        entidad_federativa_id: null,
        codigo_postal: '',
        representante: '',
        sitio_web: '',
        telefono: '',
        correo: '',
        cupo_total: null,
        notas: '',
    };
    creando.value = true;
}

function guardar(): void {
    procesando.value = true;

    router.post('/procesos/organizaciones', { ...datos.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (creando.value = false),
        onFinish: () => (procesando.value = false),
    });
}
</script>

<template>
    <Head title="Organizaciones receptoras" />

    <AppLayout titulo="Organizaciones receptoras">
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Con quién se puede hacer el servicio social o las prácticas</h2>
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        El padrón es de toda la escuela: una dependencia no pertenece a un campus. Lo que
                        decide a quién se le puede mandar es su <strong>situación</strong> —sólo una recibe
                        alumnos— y su <strong>alcance</strong>, que se configura en cada ficha.
                        Una organización sin alcances declarados <strong>sirve para todo</strong>.
                    </p>
                </div>

                <BotonAccion v-if="puedeEditar" variante="nuevo" texto="Nueva organización" @click="abrirAlta" />
            </div>
        </section>

        <!-- Filtros -->
        <section class="tarjeta mb-4 p-4">
            <div class="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <CampoTexto v-model="busca" etiqueta="Buscar" marcador="Nombre o RFC…" @keyup.enter="filtrar" />
                <CampoSelect
                    v-model="situacionId"
                    etiqueta="Situación"
                    :opciones="catalogos.situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    vacio="Todas"
                    @update:model-value="filtrar"
                />
                <CampoSelect
                    v-model="sectorId"
                    etiqueta="Sector"
                    :opciones="catalogos.sectores.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    vacio="Todos"
                    @update:model-value="filtrar"
                />
                <CampoSelect
                    v-model="campusId"
                    etiqueta="Sirve al campus"
                    :opciones="catalogos.campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    vacio="Cualquiera"
                    ayuda="Incluye las que no declaran alcance: ésas sirven en todos."
                    @update:model-value="filtrar"
                />
                <BotonPrincipal class="alinea-con-campo" tipo="button" texto="Buscar" icono="ninguno" @click="filtrar" />
            </div>
        </section>

        <!-- Listado -->
        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="!vacio" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Organización</th>
                            <th class="px-4 py-3 font-semibold">Sector</th>
                            <th class="px-4 py-3 font-semibold">Situación</th>
                            <th class="px-4 py-3 font-semibold text-center">Convenios</th>
                            <th class="px-4 py-3 font-semibold text-center">Plazas</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in organizaciones.data" :key="o.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="font-semibold text-contenido">{{ o.nombre_comercial || o.razon_social }}</span>
                                <span v-if="o.nombre_comercial" class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ o.razon_social }}</span>
                                <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    <span v-if="o.rfc" class="font-mono">{{ o.rfc }}</span>
                                    <span v-if="o.rfc && o.municipio"> · </span>
                                    <span v-if="o.municipio">{{ o.municipio }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ o.sector ?? '—' }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="o.situacion ?? '—'" :color="o.recibe ? '#16a34a' : 'var(--color-suave)'" />
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums">
                                <!-- Los que HOY amparan. Contarlos todos diría que
                                     se le puede mandar gente teniendo sólo vencidos. -->
                                <span :style="{ color: o.convenios_vigentes ? undefined : '#b45309' }">{{ o.convenios_vigentes }}</span>
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums">{{ o.plazas }}</td>
                            <td class="px-6 py-4">
                                <div class="flex justify-end">
                                    <BotonAccion variante="ver" texto="Abrir" :href="`/procesos/organizaciones/${o.id}`" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay organizaciones que coincidan.
                </p>
            </div>

            <Paginacion
                :enlaces="organizaciones.links"
                :total="organizaciones.total"
                :desde="organizaciones.from"
                :hasta="organizaciones.to"
            />
        </section>

        <Modal v-if="creando" etiqueta="Nueva organización" ancho="max-w-3xl" @cerrar="creando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Nueva organización</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="datos.razon_social" etiqueta="Razón social" requerido :error="errores.razon_social" />
                        <CampoTexto
                            v-model="datos.nombre_comercial"
                            etiqueta="Nombre comercial"
                            ayuda="Con el que la conoce todo el mundo. Es el que se busca."
                            :error="errores.nombre_comercial"
                        />
                        <CampoTexto v-model="datos.rfc" etiqueta="RFC" ayuda="Opcional, pero no se repite: evita el duplicado." :error="errores.rfc" />
                        <CampoSelect
                            v-model="datos.situacion_id"
                            etiqueta="Situación"
                            requerido
                            :opciones="catalogos.situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                            :error="errores.situacion_id"
                        />
                        <CampoSelect
                            v-model="datos.sector_id"
                            etiqueta="Sector"
                            :opciones="catalogos.sectores.map((s) => ({ valor: s.id, texto: s.nombre }))"
                            vacio="Sin señalar"
                            :error="errores.sector_id"
                        />
                        <CampoSelect
                            v-model="datos.tipo_id"
                            etiqueta="Tipo"
                            :opciones="catalogos.tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Sin señalar"
                            :error="errores.tipo_id"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto v-model="datos.representante" etiqueta="Representante" :error="errores.representante" />
                        <CampoTexto v-model="datos.telefono" etiqueta="Teléfono" :error="errores.telefono" />
                        <CampoTexto v-model="datos.correo" etiqueta="Correo" :error="errores.correo" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-4">
                        <CampoTexto v-model="datos.calle" etiqueta="Calle y número" :error="errores.calle" />
                        <CampoTexto v-model="datos.colonia" etiqueta="Colonia" :error="errores.colonia" />
                        <CampoTexto v-model="datos.municipio" etiqueta="Municipio" :error="errores.municipio" />
                        <CampoTexto v-model="datos.codigo_postal" etiqueta="C.P." :error="errores.codigo_postal" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoSelect
                            v-model="datos.entidad_federativa_id"
                            etiqueta="Entidad"
                            :opciones="catalogos.entidades.map((e) => ({ valor: e.id, texto: e.nombre }))"
                            vacio="Sin señalar"
                            :error="errores.entidad_federativa_id"
                        />
                        <CampoTexto v-model="datos.sitio_web" etiqueta="Sitio web" marcador="https://…" :error="errores.sitio_web" />
                        <CampoTexto
                            v-model.number="datos.cupo_total"
                            etiqueta="Cupo total"
                            tipo="number"
                            paso="1"
                            ayuda="Si la organización lo declara. El que se controla es el de cada plaza."
                            :error="errores.cupo_total"
                        />
                    </div>

                    <CampoTextarea v-model="datos.notas" etiqueta="Notas" :filas="2" :error="errores.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Dar de alta" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
