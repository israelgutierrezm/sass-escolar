<script setup lang="ts">
/**
 * La ficha de una organización receptora: sus datos, con quién se habla, hasta
 * dónde alcanza, sus convenios y sus plazas.
 *
 * ── El ALCANCE es lo que más se malinterpreta ──────────────────────────────
 * Sin filas, la organización sirve para TODO. La pantalla lo dice con palabras
 * en vez de dejar una lista vacía, porque un hueco se lee como captura
 * incompleta y alguien acabaría agregando veinte filas para «no dejarlo vacío»
 * —y con eso la acotaría sin querer—.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Opcion { id: number; nombre: string }

interface Contacto {
    id: number;
    nombre: string;
    cargo: string | null;
    correo: string | null;
    telefono: string | null;
    es_principal: boolean;
    es_supervisor: boolean;
}

const props = defineProps<{
    organizacion: Record<string, any>;
    contactos: Contacto[];
    alcances: { id: number; texto: string }[];
    convenios: Record<string, any>[];
    plazas: Record<string, any>[];
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

const errores = ref<Record<string, string>>({});
const procesando = ref(false);

/* ── Datos ──────────────────────────────────────────────────────────────── */
const editando = ref(false);
const datos = ref<Record<string, unknown>>({ ...props.organizacion });

function abrirEdicion(): void {
    errores.value = {};
    datos.value = { ...props.organizacion };
    editando.value = true;
}

function guardar(): void {
    procesando.value = true;

    router.put(`/procesos/organizaciones/${props.organizacion.id}`, { ...datos.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (editando.value = false),
        onFinish: () => (procesando.value = false),
    });
}

/* ── Contactos ──────────────────────────────────────────────────────────── */
const contacto = ref<Record<string, unknown> | null>(null);
const contactoId = ref<number | null>(null);

function abrirContacto(c: Contacto | null): void {
    errores.value = {};
    contactoId.value = c?.id ?? null;
    contacto.value = {
        nombre: c?.nombre ?? '',
        cargo: c?.cargo ?? '',
        correo: c?.correo ?? '',
        telefono: c?.telefono ?? '',
        es_principal: c?.es_principal ?? props.contactos.length === 0,
        es_supervisor: c?.es_supervisor ?? false,
    };
}

function guardarContacto(): void {
    procesando.value = true;

    const base = `/procesos/organizaciones/${props.organizacion.id}/contactos`;
    const destino = contactoId.value ? `${base}/${contactoId.value}` : base;

    router[contactoId.value ? 'put' : 'post'](destino, { ...contacto.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (contacto.value = null),
        onFinish: () => (procesando.value = false),
    });
}

function eliminarContacto(c: Contacto): void {
    if (!confirm(`¿Retirar a ${c.nombre} de los contactos?`)) {
        return;
    }

    router.delete(`/procesos/organizaciones/${props.organizacion.id}/contactos/${c.id}`, { preserveScroll: true });
}

/* ── Alcances ───────────────────────────────────────────────────────────── */
const alcance = ref<Record<string, unknown> | null>(null);

function abrirAlcance(): void {
    errores.value = {};
    alcance.value = { campus_id: null, programa_academico_id: null, tipo_proceso_id: null };
}

function guardarAlcance(): void {
    procesando.value = true;

    router.post(`/procesos/organizaciones/${props.organizacion.id}/alcances`, { ...alcance.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (alcance.value = null),
        onFinish: () => (procesando.value = false),
    });
}

function eliminarAlcance(id: number): void {
    router.delete(`/procesos/organizaciones/${props.organizacion.id}/alcances/${id}`, { preserveScroll: true });
}

const sinAlcances = computed(() => props.alcances.length === 0);
</script>

<template>
    <Head :title="organizacion.nombre_comercial || organizacion.razon_social" />

    <AppLayout :titulo="organizacion.nombre_comercial || organizacion.razon_social">
        <Link href="/procesos/organizaciones" class="mb-4 inline-block text-sm" :style="{ color: 'var(--color-acento)' }">
            ← Todas las organizaciones
        </Link>

        <!-- Datos -->
        <TarjetaSeccion titulo="Datos" :descripcion="organizacion.razon_social">
            <template #insignia>
                <div class="flex items-center gap-2">
                    <PildoraEstado :texto="organizacion.situacion ?? '—'" :color="organizacion.recibe ? '#16a34a' : 'var(--color-suave)'" />
                    <BotonAccion v-if="puedeEditar" variante="editar" @click="abrirEdicion" />
                </div>
            </template>

            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div v-for="d in [
                    { t: 'RFC', v: organizacion.rfc },
                    { t: 'Representante', v: organizacion.representante },
                    { t: 'Teléfono', v: organizacion.telefono },
                    { t: 'Correo', v: organizacion.correo },
                    { t: 'Domicilio', v: [organizacion.calle, organizacion.colonia, organizacion.municipio].filter(Boolean).join(', ') },
                    { t: 'Cupo total', v: organizacion.cupo_total },
                ]" :key="d.t">
                    <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ d.t }}</dt>
                    <dd class="mt-0.5 break-words">{{ d.v || '—' }}</dd>
                </div>
            </dl>

            <p v-if="organizacion.notas" class="mt-4 rounded-lg px-4 py-3 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)', color: 'var(--color-suave)' }">
                {{ organizacion.notas }}
            </p>
        </TarjetaSeccion>

        <!-- Contactos -->
        <TarjetaSeccion titulo="Contactos" descripcion="Con quién se habla, y quién supervisa a los alumnos." class="mt-6" sin-relleno>
            <template v-if="puedeEditar" #insignia>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="abrirContacto(null)"
                >Agregar</button>
            </template>

            <ul>
                <li
                    v-for="c in contactos"
                    :key="c.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            <span>{{ c.nombre }}</span>
                            <span v-if="c.es_principal" class="rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)', color: 'var(--color-acento)' }">Principal</span>
                            <span v-if="c.es_supervisor" class="rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, #0077B6 14%, transparent)', color: '#0077B6' }">Supervisa</span>
                        </p>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ [c.cargo, c.correo, c.telefono].filter(Boolean).join(' · ') || 'Sin datos de contacto' }}
                        </p>
                    </div>

                    <MenuAcciones
                        :opciones="puedeEditar ? [
                            { variante: 'editar', clave: 'editar' },
                            { variante: 'eliminar', clave: 'eliminar' },
                        ] : []"
                        @elegir="(q) => q === 'editar' ? abrirContacto(c) : eliminarContacto(c)"
                    />
                </li>

                <li v-if="!contactos.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay contactos.
                </li>
            </ul>
        </TarjetaSeccion>

        <!-- Alcances -->
        <TarjetaSeccion titulo="Hasta dónde alcanza" class="mt-6" sin-relleno>
            <template v-if="puedeEditar" #insignia>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="abrirAlcance"
                >Agregar</button>
            </template>

            <!--
                El vacío se EXPLICA en vez de dejarse en blanco: «sin alcances»
                significa «sirve para todo», y una lista vacía se lee como
                captura incompleta.
            -->
            <p
                v-if="sinAlcances"
                class="px-6 py-6 text-sm"
                :style="{ color: 'var(--color-suave)' }"
            >
                Sin alcances declarados, esta organización <strong>sirve para cualquier campus, programa
                y tipo de proceso</strong>. Agrega uno sólo si quieres acotarla.
            </p>

            <ul v-else>
                <li
                    v-for="a in alcances"
                    :key="a.id"
                    class="flex items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span>{{ a.texto }}</span>
                    <BotonAccion v-if="puedeEditar" variante="eliminar" @click="eliminarAlcance(a.id)" />
                </li>
            </ul>
        </TarjetaSeccion>

        <!-- Convenios -->
        <TarjetaSeccion titulo="Convenios" descripcion="Vencido y suspendido son cosas distintas: aquí se ven las dos." class="mt-6" sin-relleno>
            <ul>
                <li
                    v-for="c in convenios"
                    :key="c.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ c.folio }} <span class="text-xs" :style="{ color: 'var(--color-suave)' }">v{{ c.version }}</span></p>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ c.tipo ?? 'Sin tipo' }} · {{ c.vigente_desde }} → {{ c.vigente_hasta ?? 'sin término' }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <PildoraEstado :texto="c.situacion ?? '—'" :color="c.vigente ? '#16a34a' : 'var(--color-suave)'" />
                        <span v-if="c.vencido" class="text-xs" :style="{ color: '#b45309' }">Venció</span>
                        <span
                            v-else-if="c.dias_para_vencer !== null && c.dias_para_vencer <= 60"
                            class="text-xs"
                            :style="{ color: '#b45309' }"
                        >Vence en {{ c.dias_para_vencer }} día(s)</span>
                    </div>
                </li>

                <li v-if="!convenios.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Sin convenios. Se dan de alta desde <Link href="/procesos/convenios" :style="{ color: 'var(--color-acento)' }">Convenios</Link>.
                </li>
            </ul>
        </TarjetaSeccion>

        <!-- Plazas -->
        <TarjetaSeccion titulo="Plazas y proyectos" class="mt-6" sin-relleno>
            <ul>
                <li
                    v-for="p in plazas"
                    :key="p.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ p.nombre }}</p>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.tipo }}</p>
                    </div>
                    <span class="text-xs tabular-nums" :style="{ color: p.admite ? 'var(--color-suave)' : '#b45309' }">
                        {{ p.cupo_ocupado }} de {{ p.cupo }}{{ p.admite ? '' : ' · no recibe' }}
                    </span>
                </li>

                <li v-if="!plazas.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Sin plazas. Se dan de alta desde <Link href="/procesos/plazas" :style="{ color: 'var(--color-acento)' }">Plazas y proyectos</Link>.
                </li>
            </ul>
        </TarjetaSeccion>

        <!-- Diálogos -->
        <Modal v-if="editando" etiqueta="Editar organización" ancho="max-w-3xl" @cerrar="editando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Editar organización</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="datos.razon_social" etiqueta="Razón social" requerido :error="errores.razon_social" />
                        <CampoTexto v-model="datos.nombre_comercial" etiqueta="Nombre comercial" :error="errores.nombre_comercial" />
                        <CampoTexto v-model="datos.rfc" etiqueta="RFC" :error="errores.rfc" />
                        <CampoSelect
                            v-model="datos.situacion_id"
                            etiqueta="Situación"
                            requerido
                            :opciones="catalogos.situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                            ayuda="Sólo la que recibe alumnos permite asignarle a alguien."
                            :error="errores.situacion_id"
                        />
                        <CampoSelect v-model="datos.sector_id" etiqueta="Sector" :opciones="catalogos.sectores.map((s) => ({ valor: s.id, texto: s.nombre }))" vacio="Sin señalar" />
                        <CampoSelect v-model="datos.tipo_id" etiqueta="Tipo" :opciones="catalogos.tipos.map((t) => ({ valor: t.id, texto: t.nombre }))" vacio="Sin señalar" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto v-model="datos.representante" etiqueta="Representante" />
                        <CampoTexto v-model="datos.telefono" etiqueta="Teléfono" />
                        <CampoTexto v-model="datos.correo" etiqueta="Correo" :error="errores.correo" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-4">
                        <CampoTexto v-model="datos.calle" etiqueta="Calle y número" />
                        <CampoTexto v-model="datos.colonia" etiqueta="Colonia" />
                        <CampoTexto v-model="datos.municipio" etiqueta="Municipio" />
                        <CampoTexto v-model="datos.codigo_postal" etiqueta="C.P." />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoSelect v-model="datos.entidad_federativa_id" etiqueta="Entidad" :opciones="catalogos.entidades.map((e) => ({ valor: e.id, texto: e.nombre }))" vacio="Sin señalar" />
                        <CampoTexto v-model="datos.sitio_web" etiqueta="Sitio web" :error="errores.sitio_web" />
                        <CampoTexto v-model.number="datos.cupo_total" etiqueta="Cupo total" tipo="number" paso="1" :error="errores.cupo_total" />
                    </div>

                    <CampoTextarea v-model="datos.notas" etiqueta="Notas" :filas="2" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Guardar" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="contacto" etiqueta="Contacto" @cerrar="contacto = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarContacto">
                    <h2 class="text-base font-semibold">{{ contactoId ? 'Editar' : 'Agregar' }} contacto</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="contacto.nombre" etiqueta="Nombre" requerido :error="errores.nombre" />
                        <CampoTexto v-model="contacto.cargo" etiqueta="Cargo" :error="errores.cargo" />
                        <CampoTexto v-model="contacto.correo" etiqueta="Correo" :error="errores.correo" />
                        <CampoTexto v-model="contacto.telefono" etiqueta="Teléfono" :error="errores.telefono" />
                    </div>

                    <label class="flex items-start gap-3 text-sm">
                        <input v-model="contacto.es_principal" type="checkbox" class="mt-0.5 h-4 w-4" />
                        <span>
                            <span class="font-medium">Es el contacto principal</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">Sólo puede haber uno: marcarlo aquí desmarca al anterior.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 text-sm">
                        <input v-model="contacto.es_supervisor" type="checkbox" class="mt-0.5 h-4 w-4" />
                        <span>
                            <span class="font-medium">Supervisa alumnos</span>
                            <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">Quien está al lado del practicante. No siempre es el mismo que firma el convenio.</span>
                        </span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :texto="contactoId ? 'Guardar' : 'Agregar'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="alcance" etiqueta="Alcance" @cerrar="alcance = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarAlcance">
                    <h2 class="text-base font-semibold">Acotar la organización</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        Lo que dejes sin elegir <strong>no acota</strong>. Cada alcance vale por su cuenta:
                        basta que uno case para que la organización sirva.
                    </p>

                    <CampoSelect v-model="alcance.campus_id" etiqueta="Campus" :opciones="catalogos.campus.map((c) => ({ valor: c.id, texto: c.nombre }))" vacio="Cualquiera" />
                    <CampoSelect v-model="alcance.programa_academico_id" etiqueta="Programa académico" :opciones="catalogos.programas.map((p) => ({ valor: p.id, texto: p.nombre }))" vacio="Cualquiera" />
                    <CampoSelect v-model="alcance.tipo_proceso_id" etiqueta="Tipo de proceso" :opciones="catalogos.tiposProceso.map((t) => ({ valor: t.id, texto: t.nombre }))" vacio="Cualquiera" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Agregar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
