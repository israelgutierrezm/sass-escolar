<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Tipo {
    id: number;
    nombre: string;
    tiene_vigencia: boolean;
}

interface Sancion {
    id: number;
    matricula_oferta_id: number;
    matricula: string | null;
    alumno: string | null;
    tipo_id: number;
    tipo: string | null;
    tiene_vigencia: boolean;
    fecha: string | null;
    desde: string | null;
    hasta: string | null;
    vigente: boolean;
    motivo: string;
    aplica: string | null;
    incidencias: { id: number; descripcion: string; fecha: string | null }[];
}

interface IncidenciaCitable {
    id: number;
    tipo: string | null;
    fecha: string | null;
    descripcion: string;
}

const props = defineProps<{
    sanciones: { data: Sancion[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { busqueda: string; tipo_id: number | null };
    tipos: Tipo[];
}>();

const busqueda = ref(props.filtros.busqueda);
const tipoFiltro = ref(props.filtros.tipo_id);

const registrando = ref(false);
const editando = ref<Sancion | null>(null);

// Las incidencias del alumno elegido, para poder citarlas.
const incidenciasCitables = ref<IncidenciaCitable[]>([]);

const form = useForm({
    matricula_oferta_id: null as number | null,
    tipo_sancion_id: null as number | null,
    fecha: hoyLocal(),
    desde: '',
    hasta: '',
    motivo: '',
    incidencias: [] as number[],
});

// El tipo elegido decide si se piden fechas de vigencia.
const tipoElegido = computed(() => props.tipos.find((t) => t.id === form.tipo_sancion_id) ?? null);
const pideVigencia = computed(() => tipoElegido.value?.tiene_vigencia ?? false);

// Al elegir el alumno se traen sus incidencias para ofrecerlas.
watch(() => form.matricula_oferta_id, async (id) => {
    incidenciasCitables.value = [];
    form.incidencias = [];

    if (id == null) return;

    try {
        const { data } = await axios.get(`/escolar/sanciones/incidencias/${id}`);
        incidenciasCitables.value = data;
    } catch {
        incidenciasCitables.value = [];
    }
});

function filtrar(): void {
    router.get('/escolar/sanciones', { busqueda: busqueda.value, tipo_id: tipoFiltro.value }, {
        preserveState: true,
        replace: true,
    });
}

function abrirAlta(): void {
    editando.value = null;
    form.reset();
    form.fecha = hoyLocal();
    form.clearErrors();
    incidenciasCitables.value = [];
    registrando.value = true;
}

async function abrirEdicion(s: Sancion): Promise<void> {
    editando.value = s;
    form.matricula_oferta_id = s.matricula_oferta_id;
    form.tipo_sancion_id = s.tipo_id;
    form.fecha = s.fecha ?? hoyLocal();
    form.desde = s.desde ?? '';
    form.hasta = s.hasta ?? '';
    form.motivo = s.motivo;
    form.clearErrors();
    registrando.value = true;

    // Traer las incidencias del alumno y marcar las ya citadas. El `watch` del
    // alumno no corre aquí porque el id no cambia entre ediciones, así que se
    // piden a mano.
    try {
        const { data } = await axios.get(`/escolar/sanciones/incidencias/${s.matricula_oferta_id}`);
        incidenciasCitables.value = data;
    } catch {
        incidenciasCitables.value = [];
    }
    form.incidencias = s.incidencias.map((i) => i.id);
}

function guardar(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            registrando.value = false;
            form.reset();
        },
    };

    // Lo que el tipo no usa no se manda: si no tiene vigencia, fechas vacías.
    form.transform((d) => ({
        ...d,
        desde: pideVigencia.value && d.desde !== '' ? d.desde : null,
        hasta: pideVigencia.value && d.hasta !== '' ? d.hasta : null,
    }));

    if (editando.value) {
        form.put(`/escolar/sanciones/${editando.value.id}`, opciones);
    } else {
        form.post('/escolar/sanciones', opciones);
    }
}

function eliminar(s: Sancion): void {
    if (!confirm(`¿Retirar la sanción de ${s.alumno ?? 'este alumno'}? Queda en el historial con su auditoría.`)) {
        return;
    }

    router.delete(`/escolar/sanciones/${s.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Sanciones" />

    <AppLayout titulo="Sanciones">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Las sanciones aplicadas. Las que tienen vigencia —una suspensión— llevan sus fechas.
            </p>

            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrirAlta"
            >
                Aplicar sanción
            </button>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <CampoTexto v-model="busqueda" etiqueta="" marcador="Matrícula o nombre…" @keyup.enter="filtrar" />
            <CampoSelect
                v-model="tipoFiltro"
                etiqueta=""
                :opciones="tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                vacio="Todos los tipos"
                @update:model-value="filtrar"
            />
            <button
                type="button"
                class="justify-self-start rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @click="filtrar"
            >
                Buscar
            </button>
        </div>

        <TarjetaSeccion titulo="Sanciones" sin-relleno>
            <ul v-if="sanciones.data.length">
                <li
                    v-for="s in sanciones.data"
                    :key="s.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            <span>{{ s.alumno ?? '—' }}</span>
                            <span v-if="s.matricula" class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ s.matricula }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, #7c3aed 14%, transparent)', color: '#7c3aed' }"
                            >{{ s.tipo }}</span>
                            <span
                                v-if="s.vigente"
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, #dc2626 14%, transparent)', color: '#dc2626' }"
                            >Vigente</span>
                        </p>
                        <p class="mt-0.5 whitespace-pre-line text-sm">{{ s.motivo }}</p>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ s.fecha }}
                            <template v-if="s.desde"> · del {{ s.desde }} al {{ s.hasta }}</template>
                            <template v-if="s.aplica"> · aplicó {{ s.aplica }}</template>
                        </p>
                        <p v-if="s.incidencias.length" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                            Por {{ s.incidencias.length }} incidencia(s): {{ s.incidencias.map((i) => i.descripcion).join('; ') }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="abrirEdicion(s)"
                        >Editar</button>
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: '#dc2626' }"
                            @click="eliminar(s)"
                        >Retirar</button>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay sanciones registradas.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="sanciones.links"
            :total="sanciones.total"
            :desde="sanciones.from"
            :hasta="sanciones.to"
            class="mt-4"
        />

        <Modal v-if="registrando" :etiqueta="editando ? 'Editar sanción' : 'Aplicar sanción'" :formulario="form" @cerrar="registrando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">{{ editando ? 'Editar sanción' : 'Aplicar sanción' }}</h2>

                    <div v-if="editando" class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">Alumno</span>
                        <p class="font-medium">{{ editando.alumno }} <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ editando.matricula }}</span></p>
                    </div>
                    <BuscadorRemoto
                        v-else
                        v-model="form.matricula_oferta_id"
                        url="/buscar/matriculas"
                        etiqueta="Alumno"
                        marcador="Matrícula o nombre…"
                        :error="form.errors.matricula_oferta_id"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="form.tipo_sancion_id"
                            etiqueta="Tipo"
                            :opciones="tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Selecciona…"
                            :error="form.errors.tipo_sancion_id"
                        />
                        <CampoTexto v-model="form.fecha" etiqueta="Fecha" tipo="date" requerido :error="form.errors.fecha" />
                    </div>

                    <!-- La vigencia sólo cuando el tipo la usa: es lo que hace
                         del catálogo algo configurable. -->
                    <div v-if="pideVigencia" class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="form.desde" etiqueta="Desde" tipo="date" :error="form.errors.desde" />
                        <CampoTexto v-model="form.hasta" etiqueta="Hasta" tipo="date" :error="form.errors.hasta" />
                    </div>

                    <CampoTextarea
                        v-model="form.motivo"
                        etiqueta="Motivo"
                        :filas="3"
                        :error="form.errors.motivo"
                    />

                    <!-- Las incidencias del alumno, para citar las que la
                         originaron. Aparecen al elegir al alumno. -->
                    <div v-if="incidenciasCitables.length">
                        <p class="mb-1 text-sm font-medium text-contenido">Incidencias que la originaron</p>
                        <p class="mb-2 text-xs" :style="{ color: 'var(--color-suave)' }">Opcional. Sólo salen las de este alumno.</p>
                        <ul class="space-y-1">
                            <li v-for="inc in incidenciasCitables" :key="inc.id">
                                <label class="flex cursor-pointer items-start gap-2 text-sm">
                                    <input v-model="form.incidencias" type="checkbox" :value="inc.id" class="mt-0.5" />
                                    <span>
                                        <strong>{{ inc.tipo }}</strong>
                                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }"> · {{ inc.fecha }}</span>
                                        — {{ inc.descripcion }}
                                    </span>
                                </label>
                            </li>
                        </ul>
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" :texto="editando ? 'Guardar' : 'Aplicar'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
