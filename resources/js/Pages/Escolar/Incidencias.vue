<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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

interface Incidencia {
    id: number;
    matricula_oferta_id: number;
    matricula: string | null;
    alumno: string | null;
    tipo_id: number;
    tipo: string | null;
    nivel: number;
    fecha: string | null;
    descripcion: string;
    reporta: string | null;
}

const props = defineProps<{
    incidencias: { data: Incidencia[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { busqueda: string; tipo_id: number | null };
    tipos: { id: number; nombre: string; nivel: number }[];
}>();

const busqueda = ref(props.filtros.busqueda);
const tipoFiltro = ref(props.filtros.tipo_id);

const registrando = ref(false);
const editando = ref<Incidencia | null>(null);

const form = useForm({
    matricula_oferta_id: null as number | null,
    tipo_incidencia_id: null as number | null,
    fecha: hoyLocal(),
    descripcion: '',
});

function filtrar(): void {
    router.get('/escolar/incidencias', { busqueda: busqueda.value, tipo_id: tipoFiltro.value }, {
        preserveState: true,
        replace: true,
    });
}

function abrirAlta(): void {
    editando.value = null;
    form.reset();
    form.fecha = hoyLocal();
    form.clearErrors();
    registrando.value = true;
}

function abrirEdicion(i: Incidencia): void {
    editando.value = i;
    form.matricula_oferta_id = i.matricula_oferta_id;
    form.tipo_incidencia_id = i.tipo_id;
    form.fecha = i.fecha ?? hoyLocal();
    form.descripcion = i.descripcion;
    form.clearErrors();
    registrando.value = true;
}

function guardar(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            registrando.value = false;
            form.reset();
        },
    };

    if (editando.value) {
        form.put(`/escolar/incidencias/${editando.value.id}`, opciones);
    } else {
        form.post('/escolar/incidencias', opciones);
    }
}

function eliminar(i: Incidencia): void {
    if (!confirm(`¿Retirar la incidencia de ${i.alumno ?? 'este alumno'}? Queda en el historial con su auditoría.`)) {
        return;
    }

    router.delete(`/escolar/incidencias/${i.id}`, { preserveScroll: true });
}

// El color por nivel de gravedad. Se lee del catálogo (1 = leve), y el mapa
// crece con la escala: sin nivel conocido cae a gris.
function colorNivel(n: number): string {
    if (n >= 3) return '#dc2626';
    if (n === 2) return '#d97706';
    return '#16a34a';
}
</script>

<template>
    <Head title="Incidencias" />

    <AppLayout titulo="Incidencias">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Las conductas registradas de los alumnos. Una sanción puede citar las que la originaron.
            </p>

            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrirAlta"
            >
                Registrar incidencia
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

        <TarjetaSeccion titulo="Incidencias" sin-relleno>
            <ul v-if="incidencias.data.length">
                <li
                    v-for="i in incidencias.data"
                    :key="i.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            <span>{{ i.alumno ?? '—' }}</span>
                            <span v-if="i.matricula" class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ i.matricula }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :style="{
                                    backgroundColor: `color-mix(in srgb, ${colorNivel(i.nivel)} 14%, transparent)`,
                                    color: colorNivel(i.nivel),
                                }"
                            >{{ i.tipo }}</span>
                        </p>
                        <p class="mt-0.5 whitespace-pre-line text-sm">{{ i.descripcion }}</p>
                        <p class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ i.fecha }}<template v-if="i.reporta"> · reportó {{ i.reporta }}</template>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="abrirEdicion(i)"
                        >Editar</button>
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs"
                            :style="{ borderColor: 'var(--color-borde)', color: '#dc2626' }"
                            @click="eliminar(i)"
                        >Retirar</button>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay incidencias registradas.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="incidencias.links"
            :total="incidencias.total"
            :desde="incidencias.from"
            :hasta="incidencias.to"
            class="mt-4"
        />

        <Modal v-if="registrando" :etiqueta="editando ? 'Editar incidencia' : 'Registrar incidencia'" :formulario="form" @cerrar="registrando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">{{ editando ? 'Editar incidencia' : 'Registrar incidencia' }}</h2>

                    <!-- Al editar el alumno no cambia: una incidencia es de
                         alguien concreto. Se muestra fijo; el buscador —que
                         arranca vacío— sólo aparece al crear. -->
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
                            v-model="form.tipo_incidencia_id"
                            etiqueta="Tipo"
                            :opciones="tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Selecciona…"
                            :error="form.errors.tipo_incidencia_id"
                        />
                        <CampoTexto v-model="form.fecha" etiqueta="¿Cuándo ocurrió?" tipo="date" requerido :error="form.errors.fecha" />
                    </div>

                    <CampoTextarea
                        v-model="form.descripcion"
                        etiqueta="Descripción"
                        :filas="3"
                        ayuda="Qué pasó, con el detalle que haga falta para entenderlo después."
                        :error="form.errors.descripcion"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" :texto="editando ? 'Guardar' : 'Registrar'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
