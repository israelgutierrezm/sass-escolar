<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Convocatoria {
    id: number;
    titulo: string;
    institucion: string | null;
    direccion: string;
    periodo: string;
    cupo: number;
    libres: number;
    promedio_minimo: string | null;
    apertura: string | null;
    cierre: string | null;
    abierta: boolean;
    postulaciones: number;
}

const props = defineProps<{
    convocatorias: { data: Convocatoria[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { direccion: string | null };
    convenios: { id: number; nombre: string }[];
    requisitos: { id: number; nombre: string }[];
}>();

const direccion = ref(props.filtros.direccion);
const creando = ref(false);

const form = useForm({
    convenio_id: null as number | null,
    titulo: '',
    direccion: 'saliente',
    periodo: '',
    cupo: 1,
    promedio_minimo: '',
    fecha_apertura: hoyLocal(),
    fecha_cierre: hoyLocal(),
    descripcion: '',
    requisitos: [] as number[],
});

function filtrar(): void {
    router.get('/movilidad/convocatorias', { direccion: direccion.value }, { preserveState: true, replace: true });
}

function guardar(): void {
    form.transform((d) => ({ ...d, promedio_minimo: d.promedio_minimo === '' ? null : d.promedio_minimo }))
        .post('/movilidad/convocatorias', {
            preserveScroll: true,
            onSuccess: () => {
                creando.value = false;
                form.reset();
            },
        });
}
</script>

<template>
    <Head title="Convocatorias" />

    <AppLayout titulo="Convocatorias de movilidad">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Saliente es alguien nuestro que se va; entrante, alguien de otra institución que
                llega. Sólo al saliente se le revalidan materias después.
            </p>

            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="creando = true"
            >
                Nueva convocatoria
            </button>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <CampoSelect
                v-model="direccion"
                etiqueta=""
                :opciones="[
                    { valor: 'saliente', texto: 'Salientes' },
                    { valor: 'entrante', texto: 'Entrantes' },
                ]"
                vacio="En las dos direcciones"
                @update:model-value="filtrar"
            />
        </div>

        <TarjetaSeccion titulo="Convocatorias" sin-relleno>
            <ul v-if="convocatorias.data.length">
                <li
                    v-for="c in convocatorias.data"
                    :key="c.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <Link
                            :href="`/movilidad/convocatorias/${c.id}/postulaciones`"
                            class="font-medium"
                            :style="{ color: 'var(--color-acento)' }"
                        >
                            {{ c.titulo }}
                        </Link>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ c.institucion }} · {{ c.direccion }} · {{ c.periodo }}
                            <span v-if="c.promedio_minimo"> · pide {{ c.promedio_minimo }} de promedio</span>
                        </p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            del {{ c.apertura }} al {{ c.cierre }} · {{ c.postulaciones }} postulación(es)
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <!--
                            Los lugares LIBRES, no el cupo: el cupo lo sabe quien
                            la creó, lo que hay que decidir con esto es a cuánta
                            gente más se puede aceptar.
                        -->
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ c.libres }} de {{ c.cupo }} libres
                        </span>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :style="{
                                backgroundColor: `color-mix(in srgb, ${c.abierta ? '#16a34a' : '#dc2626'} 14%, transparent)`,
                                color: c.abierta ? '#16a34a' : '#dc2626',
                            }"
                        >
                            {{ c.abierta ? 'Abierta' : 'Cerrada' }}
                        </span>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay convocatorias.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="convocatorias.links"
            :total="convocatorias.total"
            :desde="convocatorias.from"
            :hasta="convocatorias.to"
            class="mt-4"
        />

        <Modal v-if="creando" etiqueta="Nueva convocatoria" :formulario="form" @cerrar="creando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Nueva convocatoria</h2>

                    <CampoSelect
                        v-model="form.convenio_id"
                        etiqueta="Convenio"
                        :opciones="convenios.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        vacio="Selecciona…"
                        ayuda="Sólo aparecen los convenios vigentes."
                        :error="form.errors.convenio_id"
                    />

                    <CampoTexto v-model="form.titulo" etiqueta="Título" requerido :error="form.errors.titulo" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="form.direccion"
                            etiqueta="Dirección"
                            :opciones="[
                                { valor: 'saliente', texto: 'Saliente · alguien nuestro se va' },
                                { valor: 'entrante', texto: 'Entrante · alguien de fuera llega' },
                            ]"
                            :error="form.errors.direccion"
                        />
                        <CampoTexto v-model="form.periodo" etiqueta="Periodo destino" requerido ayuda="El ciclo de allá: «2027-1»." :error="form.errors.periodo" />
                        <CampoTexto v-model="form.cupo" etiqueta="Cupo" tipo="number" requerido :error="form.errors.cupo" />
                        <CampoTexto
                            v-model="form.promedio_minimo"
                            etiqueta="Promedio mínimo"
                            tipo="number"
                            ayuda="En blanco = no lo pide. Se compara con el promedio REAL del alumno."
                            :error="form.errors.promedio_minimo"
                        />
                        <CampoTexto v-model="form.fecha_apertura" etiqueta="Abre" tipo="date" requerido :error="form.errors.fecha_apertura" />
                        <CampoTexto v-model="form.fecha_cierre" etiqueta="Cierra" tipo="date" requerido :error="form.errors.fecha_cierre" />
                    </div>

                    <!--
                        Se REUSAN los documentos requeridos de admisiones: una
                        segunda lista de papeles sería un segundo lugar donde
                        configurar «identificación oficial».
                    -->
                    <CampoCasillas
                        v-model="form.requisitos"
                        etiqueta="Requisitos documentales"
                        :opciones="requisitos.map((r) => ({ valor: r.id, texto: r.nombre }))"
                        ayuda="Salen del catálogo de documentos de la escuela."
                        :error="form.errors.requisitos"
                    />

                    <CampoTextarea v-model="form.descripcion" etiqueta="Descripción" :filas="3" :error="form.errors.descripcion" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Crear convocatoria" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
