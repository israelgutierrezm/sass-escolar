<script setup lang="ts">
/**
 * Las plazas y proyectos que ofrecen las organizaciones.
 *
 * ── El cupo se OCUPA, no se teclea ─────────────────────────────────────────
 * `cupo` lo captura quien administra; lo ocupado lo mueve la asignación. Por
 * eso aquí sólo se ve, y bajar el cupo por debajo de lo asignado se rehúsa con
 * su motivo en vez de reventar contra el CHECK de la base.
 *
 * ── Sin programas señalados, se ofrece a TODOS ─────────────────────────────
 * La pantalla lo dice con palabras: un hueco se lee como captura incompleta.
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
import Paginacion from '@/Components/Paginacion.vue';

interface Plaza {
    id: number;
    nombre: string;
    organizacion: string | null;
    organizacion_id: number;
    tipo: string | null;
    tipo_proceso_id: number;
    modalidad: string | null;
    modalidad_id: number | null;
    cupo: number;
    cupo_ocupado: number;
    libres: number;
    abierta: boolean;
    admite: boolean;
    vencida: boolean;
    fecha_inicio: string | null;
    fecha_cierre: string | null;
    duracion_estimada_horas: number | null;
    apoyo_economico: string | null;
    ubicacion: string | null;
    horario: string | null;
    descripcion: string | null;
    actividades: string | null;
    requisitos: string | null;
    responsable: string | null;
    programas: string[];
    programa_ids: number[];
}

const props = defineProps<{
    plazas: { data: Plaza[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: Record<string, unknown>;
    catalogos: {
        organizaciones: { id: number; nombre: string }[];
        tiposProceso: { id: number; nombre: string }[];
        modalidades: { id: number; nombre: string }[];
        programas: { id: number; nombre: string }[];
    };
    puedeEditar: boolean;
}>();

const busca = ref((props.filtros.busca as string) ?? '');
const organizacionId = ref((props.filtros.organizacion_id as number | null) ?? null);
const tipoProcesoId = ref((props.filtros.tipo_proceso_id as number | null) ?? null);
const soloDisponibles = ref(Boolean(props.filtros.solo_disponibles));

const errores = ref<Record<string, string>>({});
const procesando = ref(false);
const editando = ref<Plaza | null>(null);
const abierto = ref(false);
const datos = ref<Record<string, unknown>>({});

function filtrar(): void {
    router.get('/procesos/plazas', {
        busca: busca.value || undefined,
        organizacion_id: organizacionId.value ?? undefined,
        tipo_proceso_id: tipoProcesoId.value ?? undefined,
        solo_disponibles: soloDisponibles.value ? 1 : undefined,
    }, { preserveState: true, replace: true });
}

function abrir(p: Plaza | null): void {
    errores.value = {};
    editando.value = p;
    datos.value = {
        organizacion_id: p?.organizacion_id ?? null,
        tipo_proceso_id: p?.tipo_proceso_id ?? null,
        modalidad_id: p?.modalidad_id ?? null,
        nombre: p?.nombre ?? '',
        descripcion: p?.descripcion ?? '',
        actividades: p?.actividades ?? '',
        ubicacion: p?.ubicacion ?? '',
        horario: p?.horario ?? '',
        cupo: p?.cupo ?? 1,
        fecha_inicio: p?.fecha_inicio ?? '',
        fecha_cierre: p?.fecha_cierre ?? '',
        duracion_estimada_horas: p?.duracion_estimada_horas ?? null,
        apoyo_economico: p?.apoyo_economico ?? null,
        requisitos: p?.requisitos ?? '',
        responsable: p?.responsable ?? '',
        abierta: p?.abierta ?? true,
        programa_ids: [...(p?.programa_ids ?? [])],
    };
    abierto.value = true;
}

function guardar(): void {
    procesando.value = true;

    const destino = editando.value ? `/procesos/plazas/${editando.value.id}` : '/procesos/plazas';

    router[editando.value ? 'put' : 'post'](destino, { ...datos.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (abierto.value = false),
        onFinish: () => (procesando.value = false),
    });
}

function alternar(p: Plaza): void {
    router.patch(`/procesos/plazas/${p.id}/abierta`, {}, { preserveScroll: true });
}

function eliminar(p: Plaza): void {
    if (!confirm(`¿Eliminar «${p.nombre}»?`)) {
        return;
    }

    router.delete(`/procesos/plazas/${p.id}`, { preserveScroll: true });
}

function alternarPrograma(id: number): void {
    const lista = datos.value.programa_ids as number[];
    const i = lista.indexOf(id);

    i === -1 ? lista.push(id) : lista.splice(i, 1);
}

const sinProgramas = computed(() => ((datos.value.programa_ids as number[]) ?? []).length === 0);
</script>

<template>
    <Head title="Plazas y proyectos" />

    <AppLayout titulo="Plazas y proyectos">
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Lo que cada organización ofrece</h2>
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        Una plaza recibe alumnos si está <strong>abierta</strong>, tiene <strong>lugar</strong> y
                        no se le ha pasado la <strong>fecha de cierre</strong>. Las tres cuentan: una plaza
                        abierta con la fecha vencida se ve bien y no admite a nadie.
                    </p>
                </div>

                <BotonAccion v-if="puedeEditar" variante="nuevo" texto="Nueva plaza" @click="abrir(null)" />
            </div>
        </section>

        <section class="tarjeta mb-4 p-4">
            <div class="grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <CampoTexto v-model="busca" etiqueta="Buscar" marcador="Plaza u organización…" @keyup.enter="filtrar" />
                <CampoSelect
                    v-model="organizacionId"
                    etiqueta="Organización"
                    :opciones="catalogos.organizaciones.map((o) => ({ valor: o.id, texto: o.nombre }))"
                    vacio="Todas"
                    @update:model-value="filtrar"
                />
                <CampoSelect
                    v-model="tipoProcesoId"
                    etiqueta="Tipo de proceso"
                    :opciones="catalogos.tiposProceso.map((t) => ({ valor: t.id, texto: t.nombre }))"
                    vacio="Todos"
                    @update:model-value="filtrar"
                />
                <label class="flex items-center gap-2 self-center text-sm">
                    <input v-model="soloDisponibles" type="checkbox" class="h-4 w-4" @change="filtrar" />
                    <span>Sólo las que reciben</span>
                </label>
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="plazas.data.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Plaza</th>
                            <th class="px-4 py-3 font-semibold">Organización</th>
                            <th class="px-4 py-3 font-semibold">Programas</th>
                            <th class="px-4 py-3 font-semibold text-center">Cupo</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in plazas.data" :key="p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="font-semibold text-contenido">{{ p.nombre }}</span>
                                <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ [p.tipo, p.modalidad, p.ubicacion].filter(Boolean).join(' · ') }}
                                </span>
                                <span v-if="!p.admite" class="mt-0.5 block text-xs" :style="{ color: '#b45309' }">
                                    {{ !p.abierta ? 'Cerrada' : p.vencida ? 'Se le pasó la fecha' : 'Sin lugares' }}
                                </span>
                            </td>
                            <td class="px-4 py-4">
                                <Link :href="`/procesos/organizaciones/${p.organizacion_id}`" :style="{ color: 'var(--color-acento)' }">
                                    {{ p.organizacion }}
                                </Link>
                            </td>
                            <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">
                                <!-- Sin programas señalados se ofrece a todos, y
                                     se DICE: un hueco se lee como captura a medias. -->
                                {{ p.programas.length ? p.programas.join(', ') : 'Todos los programas' }}
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums">
                                {{ p.cupo_ocupado }} / {{ p.cupo }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        v-if="puedeEditar"
                                        type="button"
                                        class="rounded-lg border px-3 py-1.5 text-xs"
                                        :style="{ borderColor: 'var(--color-borde)' }"
                                        @click="alternar(p)"
                                    >{{ p.abierta ? 'Cerrar' : 'Abrir' }}</button>

                                    <MenuAcciones
                                        :opciones="puedeEditar ? [
                                            { variante: 'editar', clave: 'editar' },
                                            {
                                                variante: 'eliminar',
                                                clave: 'eliminar',
                                                deshabilitado: p.cupo_ocupado > 0,
                                                motivo: p.cupo_ocupado > 0 ? 'Tiene alumnos asignados; ciérrala en vez de borrarla' : undefined,
                                            },
                                        ] : []"
                                        @elegir="(q) => q === 'editar' ? abrir(p) : eliminar(p)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay plazas que coincidan.
                </p>
            </div>

            <Paginacion :enlaces="plazas.links" :total="plazas.total" :desde="plazas.from" :hasta="plazas.to" />
        </section>

        <Modal v-if="abierto" :etiqueta="editando ? 'Editar plaza' : 'Nueva plaza'" ancho="max-w-3xl" @cerrar="abierto = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">{{ editando ? 'Editar plaza' : 'Nueva plaza' }}</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="datos.organizacion_id"
                            etiqueta="Organización"
                            requerido
                            :opciones="catalogos.organizaciones.map((o) => ({ valor: o.id, texto: o.nombre }))"
                            vacio="Elige la organización…"
                            ayuda="Sólo salen las que hoy reciben alumnos."
                            :error="errores.organizacion_id"
                        />
                        <CampoSelect
                            v-model="datos.tipo_proceso_id"
                            etiqueta="Tipo de proceso"
                            requerido
                            :opciones="catalogos.tiposProceso.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Elige el tipo…"
                            :error="errores.tipo_proceso_id"
                        />
                    </div>

                    <CampoTexto v-model="datos.nombre" etiqueta="Nombre de la plaza o proyecto" requerido :error="errores.nombre" />
                    <CampoTextarea v-model="datos.descripcion" etiqueta="Descripción" :filas="2" :error="errores.descripcion" />
                    <CampoTextarea v-model="datos.actividades" etiqueta="Actividades" :filas="2" :error="errores.actividades" />

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoSelect
                            v-model="datos.modalidad_id"
                            etiqueta="Modalidad"
                            :opciones="catalogos.modalidades.map((m) => ({ valor: m.id, texto: m.nombre }))"
                            vacio="Sin señalar"
                        />
                        <CampoTexto v-model="datos.ubicacion" etiqueta="Ubicación" :error="errores.ubicacion" />
                        <CampoTexto v-model="datos.horario" etiqueta="Horario" :error="errores.horario" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-4">
                        <CampoTexto
                            v-model.number="datos.cupo"
                            etiqueta="Cupo"
                            tipo="number"
                            paso="1"
                            requerido
                            :ayuda="editando ? `Ocupados: ${editando.cupo_ocupado}` : undefined"
                            :error="errores.cupo"
                        />
                        <CampoTexto v-model="datos.fecha_inicio" etiqueta="Inicia" tipo="date" :error="errores.fecha_inicio" />
                        <CampoTexto v-model="datos.fecha_cierre" etiqueta="Cierra" tipo="date" :error="errores.fecha_cierre" />
                        <CampoTexto
                            v-model.number="datos.duracion_estimada_horas"
                            etiqueta="Horas estimadas"
                            tipo="number"
                            paso="1"
                            :error="errores.duracion_estimada_horas"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-model.number="datos.apoyo_economico"
                            etiqueta="Apoyo económico"
                            tipo="number"
                            paso="0.01"
                            ayuda="En blanco si no se sabe. Cero significaría que no da apoyo, que es otra cosa."
                            :error="errores.apoyo_economico"
                        />
                        <CampoTexto v-model="datos.responsable" etiqueta="Responsable" :error="errores.responsable" />
                    </div>

                    <CampoTextarea v-model="datos.requisitos" etiqueta="Requisitos" :filas="2" :error="errores.requisitos" />

                    <div>
                        <p class="mb-2 text-sm font-medium">Programas a los que se ofrece</p>
                        <p v-if="sinProgramas" class="mb-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Sin señalar ninguno, la plaza <strong>se ofrece a todos los programas</strong>.
                        </p>
                        <div class="grid max-h-48 gap-1 overflow-y-auto rounded-lg border border-borde p-2 sm:grid-cols-2">
                            <label
                                v-for="p in catalogos.programas"
                                :key="p.id"
                                class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-borde"
                                    :checked="(datos.programa_ids as number[]).includes(p.id)"
                                    @change="alternarPrograma(p.id)"
                                />
                                <span class="truncate" :title="p.nombre">{{ p.nombre }}</span>
                            </label>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="datos.abierta" type="checkbox" class="h-4 w-4" />
                        <span>Abierta: recibe asignaciones</span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :texto="editando ? 'Guardar' : 'Crear plaza'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
