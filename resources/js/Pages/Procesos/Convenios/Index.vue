<script setup lang="ts">
/**
 * Los convenios con las organizaciones receptoras.
 *
 * ── Vencido y suspendido se enseñan por SEPARADO ───────────────────────────
 * Un convenio con la situación «vigente» y la fecha pasada se ve bien en
 * cualquier pantalla que sólo mire una de las dos, y seguiría amparando
 * asignaciones nuevas. Aquí la píldora dice la situación y una nota aparte dice
 * la fecha.
 *
 * ── Renovar CREA otra fila; la vieja NO se edita ───────────────────────────
 * Cambiarle las fechas al renovarlo borraría bajo qué acuerdo estuvo cada
 * alumno que ya pasó por ahí.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Convenio {
    id: number;
    organizacion: string | null;
    organizacion_id: number;
    folio: string;
    version: number;
    tipo: string | null;
    tipo_convenio_id: number | null;
    situacion: string | null;
    situacion_id: number;
    vigente_desde: string | null;
    vigente_hasta: string | null;
    vigente: boolean;
    vencido: boolean;
    aun_no_empieza: boolean;
    ampara: boolean;
    dias_para_vencer: number | null;
    tiene_documento: boolean;
    fue_renovado: boolean;
    notas: string | null;
}

const props = defineProps<{
    convenios: { data: Convenio[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: Record<string, unknown>;
    diasAviso: number;
    porVencer: number;
    catalogos: {
        organizaciones: { id: number; nombre: string }[];
        tipos: { id: number; nombre: string }[];
        situaciones: { id: number; nombre: string; ampara_asignaciones: boolean }[];
    };
    puedeEditar: boolean;
}>();

const busca = ref((props.filtros.busca as string) ?? '');
const estado = ref((props.filtros.estado as string | null) ?? null);

const errores = ref<Record<string, string>>({});
const procesando = ref(false);

const editando = ref<Convenio | null>(null);
const creando = ref(false);
const renovando = ref<Convenio | null>(null);

const datos = ref<Record<string, unknown>>({});
const archivo = ref<File | null>(null);

const situacionVigente = computed(
    () => props.catalogos.situaciones.find((s) => s.ampara_asignaciones)?.id ?? props.catalogos.situaciones[0]?.id ?? null,
);

function filtrar(): void {
    router.get('/procesos/convenios', {
        busca: busca.value || undefined,
        estado: estado.value ?? undefined,
    }, { preserveState: true, replace: true });
}

function abrirAlta(): void {
    errores.value = {};
    archivo.value = null;
    editando.value = null;
    renovando.value = null;
    datos.value = {
        organizacion_id: null,
        tipo_convenio_id: null,
        folio: '',
        vigente_desde: '',
        vigente_hasta: '',
        situacion_id: situacionVigente.value,
        notas: '',
    };
    creando.value = true;
}

function abrirEdicion(c: Convenio): void {
    errores.value = {};
    archivo.value = null;
    creando.value = false;
    renovando.value = null;
    editando.value = c;
    datos.value = {
        organizacion_id: c.organizacion_id,
        tipo_convenio_id: c.tipo_convenio_id,
        folio: c.folio,
        vigente_desde: c.vigente_desde ?? '',
        vigente_hasta: c.vigente_hasta ?? '',
        situacion_id: c.situacion_id,
        notas: c.notas ?? '',
    };
}

function abrirRenovacion(c: Convenio): void {
    errores.value = {};
    archivo.value = null;
    creando.value = false;
    editando.value = null;
    renovando.value = c;
    datos.value = {
        folio: c.folio,
        vigente_desde: '',
        vigente_hasta: '',
        situacion_id: situacionVigente.value,
        notas: '',
    };
}

function cerrar(): void {
    creando.value = false;
    editando.value = null;
    renovando.value = null;
    errores.value = {};
}

/*
 * Con archivo, la petición va como POST con `_method`: un PUT con
 * `multipart/form-data` no lleva el archivo en PHP. Es por lo que la ruta de
 * guardar acepta POST sobre `{convenio}` en vez de PUT.
 */
function enviar(): void {
    procesando.value = true;

    const destino = renovando.value
        ? `/procesos/convenios/${renovando.value.id}/renovar`
        : editando.value
            ? `/procesos/convenios/${editando.value.id}`
            : '/procesos/convenios';

    router.post(destino, { ...datos.value, documento: archivo.value }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => cerrar(),
        onFinish: () => (procesando.value = false),
    });
}

function elegirArchivo(e: Event): void {
    archivo.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}
</script>

<template>
    <Head title="Convenios" />

    <AppLayout titulo="Convenios">
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">El acuerdo con cada organización</h2>
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        <strong>Vencido y suspendido no son lo mismo</strong>: la fecha dice lo primero y la
                        situación lo segundo, y hacen falta las dos para saber si un convenio ampara
                        asignaciones hoy. Renovar <strong>crea otro</strong> y conserva el anterior: cambiarle
                        las fechas borraría bajo qué acuerdo estuvo quien ya pasó por ahí.
                    </p>
                </div>

                <BotonAccion v-if="puedeEditar" variante="nuevo" texto="Nuevo convenio" @click="abrirAlta" />
            </div>

            <!-- La cifra que hace que alguien entre a esta pantalla. -->
            <button
                v-if="porVencer > 0"
                type="button"
                class="mt-4 block w-full rounded-lg px-4 py-3 text-left text-sm"
                :style="{ backgroundColor: 'color-mix(in srgb, #b45309 10%, transparent)', color: '#b45309' }"
                @click="estado = 'por_vencer'; filtrar()"
            >
                <strong>{{ porVencer }}</strong> convenio(s) vencen en los próximos {{ diasAviso }} días.
            </button>
        </section>

        <section class="tarjeta mb-4 p-4">
            <div class="grid items-start gap-3 sm:grid-cols-3">
                <CampoTexto v-model="busca" etiqueta="Buscar" marcador="Folio u organización…" @keyup.enter="filtrar" />
                <CampoSelect
                    v-model="estado"
                    etiqueta="Estado"
                    :opciones="[
                        { valor: 'vigentes', texto: 'Amparan hoy' },
                        { valor: 'por_vencer', texto: `Vencen en ${diasAviso} días` },
                        { valor: 'vencidos', texto: 'Ya vencieron' },
                    ]"
                    vacio="Todos"
                    @update:model-value="filtrar"
                />
                <BotonPrincipal class="alinea-con-campo" tipo="button" texto="Buscar" icono="ninguno" @click="filtrar" />
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="convenios.data.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Convenio</th>
                            <th class="px-4 py-3 font-semibold">Organización</th>
                            <th class="px-4 py-3 font-semibold">Vigencia</th>
                            <th class="px-4 py-3 font-semibold">Situación</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in convenios.data" :key="c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="font-semibold text-contenido">{{ c.folio }}</span>
                                <span class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">v{{ c.version }}</span>
                                <span v-if="c.fue_renovado" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">· renovado</span>
                                <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.tipo ?? 'Sin tipo' }}</span>
                            </td>
                            <td class="px-4 py-4">{{ c.organizacion ?? '—' }}</td>
                            <td class="px-4 py-4 text-xs">
                                <span>{{ c.vigente_desde }} → {{ c.vigente_hasta ?? 'sin término' }}</span>
                                <span v-if="c.vencido" class="mt-0.5 block font-medium" :style="{ color: '#b45309' }">Venció</span>
                                <!-- Firmado por adelantado: es lo normal en una
                                     renovación, así que se dice en tono neutro
                                     y no en el ámbar de «algo va mal». -->
                                <span
                                    v-else-if="c.aun_no_empieza"
                                    class="mt-0.5 block"
                                    :style="{ color: 'var(--color-suave)' }"
                                >Empieza más adelante</span>
                                <span
                                    v-else-if="c.dias_para_vencer !== null && c.dias_para_vencer <= diasAviso"
                                    class="mt-0.5 block font-medium"
                                    :style="{ color: '#b45309' }"
                                >Vence en {{ c.dias_para_vencer }} día(s)</span>
                            </td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="c.situacion ?? '—'" :color="c.vigente ? '#16a34a' : 'var(--color-suave)'" />
                                <!-- Que la situación ampare no significa que el
                                     convenio valga: la fecha también cuenta. -->
                                <span
                                    v-if="c.ampara && !c.vigente"
                                    class="mt-0.5 block text-xs"
                                    :style="{ color: c.aun_no_empieza ? 'var(--color-suave)' : '#b45309' }"
                                >
                                    {{ c.aun_no_empieza ? 'Todavía no ampara' : 'No ampara: ya venció' }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1">
                                    <BotonAccion
                                        v-if="c.tiene_documento"
                                        variante="ver"
                                        texto="PDF"
                                        :href="`/procesos/convenios/${c.id}/documento`"
                                    />
                                    <MenuAcciones
                                        :opciones="puedeEditar ? [
                                            { variante: 'editar', clave: 'editar' },
                                            {
                                                variante: 'agregar',
                                                clave: 'renovar',
                                                texto: 'Renovar',
                                                deshabilitado: c.fue_renovado,
                                                motivo: c.fue_renovado ? 'Ya se renovó: la renovación es la que se vuelve a renovar' : undefined,
                                            },
                                        ] : []"
                                        @elegir="(q) => q === 'editar' ? abrirEdicion(c) : abrirRenovacion(c)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay convenios que coincidan.
                </p>
            </div>

            <Paginacion :enlaces="convenios.links" :total="convenios.total" :desde="convenios.from" :hasta="convenios.to" />
        </section>

        <Modal
            v-if="creando || editando || renovando"
            :etiqueta="renovando ? 'Renovar convenio' : editando ? 'Editar convenio' : 'Nuevo convenio'"
            ancho="max-w-2xl"
            @cerrar="cerrar"
        >
            <template #default="{ cerrar: cerrarModal }">
                <form class="space-y-4 p-6" @submit.prevent="enviar">
                    <h2 class="text-base font-semibold">
                        {{ renovando ? `Renovar ${renovando.folio}` : editando ? 'Editar convenio' : 'Nuevo convenio' }}
                    </h2>

                    <p v-if="renovando" class="rounded-lg px-4 py-3 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)', color: 'var(--color-suave)' }">
                        Se crea la versión {{ renovando.version + 1 }}. El convenio anterior se conserva tal
                        cual, con sus fechas: es lo que dice bajo qué acuerdo estuvo quien ya pasó por ahí.
                    </p>

                    <div v-if="!renovando" class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="datos.organizacion_id"
                            etiqueta="Organización"
                            requerido
                            :opciones="catalogos.organizaciones.map((o) => ({ valor: o.id, texto: o.nombre }))"
                            vacio="Elige la organización…"
                            :error="errores.organizacion_id"
                        />
                        <CampoSelect
                            v-model="datos.tipo_convenio_id"
                            etiqueta="Tipo"
                            :opciones="catalogos.tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Sin señalar"
                            :error="errores.tipo_convenio_id"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="datos.folio" etiqueta="Folio" requerido :error="errores.folio" />
                        <CampoSelect
                            v-model="datos.situacion_id"
                            etiqueta="Situación"
                            requerido
                            :opciones="catalogos.situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                            ayuda="Sólo la que ampara permite asignar debajo de este convenio."
                            :error="errores.situacion_id"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="datos.vigente_desde" etiqueta="Vigente desde" tipo="date" requerido :error="errores.vigente_desde" />
                        <CampoTexto
                            v-model="datos.vigente_hasta"
                            etiqueta="Vigente hasta"
                            tipo="date"
                            ayuda="En blanco si el convenio marco no tiene término."
                            :error="errores.vigente_hasta"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-contenido">Convenio firmado (PDF)</label>
                        <input type="file" accept="application/pdf" class="w-full text-sm" @change="elegirArchivo" />
                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Se guarda en el disco privado: la descarga pasa por el sistema, no por una URL abierta.
                        </p>
                        <p v-if="errores.documento" class="mt-1 text-xs text-red-600">{{ errores.documento }}</p>
                    </div>

                    <CampoTextarea v-model="datos.notas" etiqueta="Notas" :filas="2" :error="errores.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :texto="renovando ? 'Renovar' : 'Guardar'" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrarModal">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
