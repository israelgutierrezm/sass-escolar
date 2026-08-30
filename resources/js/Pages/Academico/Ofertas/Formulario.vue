<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

const props = defineProps<{
    oferta: Record<string, any> | null;
    programas_academicos: { id: number; nombre: string }[];
    planes: { id: number; nombre: string; clave: string; programa_academico_id: number }[];
    campus: { id: number; nombre: string }[];
    modalidades: { clave: string; nombre: string }[];
}>();

const esEdicion = computed(() => props.oferta !== null);

// El alta genera una oferta por cada campus elegido (fan-out). La edición toca
// UNA oferta concreta, así que el campus vuelve a ser de un solo valor. La
// modalidad es opcional en ambos casos: no delimita la oferta, solo la describe.
const form = useForm(
    esEdicion.value
        ? {
              programa_academico_id: props.oferta!.programa_academico_id,
              plan_id: props.oferta!.plan_id,
              campus_id: props.oferta!.campus_id,
              modalidad: props.oferta!.modalidad ?? null,
              estatus: props.oferta!.estatus,
          }
        : {
              programa_academico_id: null as number | null,
              plan_id: null as number | null,
              campus_ids: [] as number[],
              modalidad: null as string | null,
              estatus: 'abierta',
          },
);

const planesDeLaProgramaAcademico = computed(() =>
    props.planes
        .filter((plan) => plan.programa_academico_id === form.programa_academico_id)
        .map((plan) => ({ valor: plan.id, texto: `${plan.nombre} (${plan.clave})` })),
);

watch(
    () => form.programa_academico_id,
    () => {
        if (!planesDeLaProgramaAcademico.value.some((plan) => plan.valor === form.plan_id)) {
            form.plan_id = null;
        }
    },
);

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

const opcionesModalidad = computed(() => props.modalidades.map((m) => ({ valor: m.clave, texto: m.nombre })));

// Cuántas ofertas generará el alta: una por campus elegido.
const combinaciones = computed(() => (esEdicion.value ? 1 : form.campus_ids.length));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/ofertas/${props.oferta!.id}`) : form.post('/academico/ofertas');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar oferta' : 'Nueva oferta'" />

    <AppLayout :titulo="esEdicion ? 'Editar oferta' : 'Nueva oferta'">
        <PestanasSeccion />

        <form class="space-y-6" @submit.prevent="enviar">
            <TarjetaSeccion titulo="Qué se imparte" :icono="ICONOS.birrete">
                <template #descripcion>
                    <template v-if="esEdicion">No puede repetirse la misma combinación de programa académico, plan y campus.</template>
                    <template v-else>Elige el programa académico y el plan, y los campus donde se ofrecerá. Se creará una oferta por campus.</template>
                </template>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <CampoSelect
                        v-model="form.programa_academico_id"
                        etiqueta="Programa académico"
                        requerido
                        :opciones="opciones(programas_academicos)"
                        vacio="Selecciona…"
                        :error="form.errors.programa_academico_id"
                    />
                    <CampoSelect
                        v-model="form.plan_id"
                        etiqueta="Plan de estudios"
                        requerido
                        :opciones="planesDeLaProgramaAcademico"
                        vacio="Selecciona…"
                        :error="form.errors.plan_id"
                        :ayuda="
                            form.programa_academico_id === null
                                ? 'Elige primero una programa_academico.'
                                : planesDeLaProgramaAcademico.length === 0
                                  ? 'Esa programa_academico no tiene planes registrados.'
                                  : undefined
                        "
                    />
                    <CampoSelect
                        v-model="form.estatus"
                        etiqueta="Estatus"
                        requerido
                        :opciones="[
                            { valor: 'abierta', texto: 'Abierta' },
                            { valor: 'cerrada', texto: 'Cerrada' },
                        ]"
                        :error="form.errors.estatus"
                        ayuda="Solo las abiertas aparecen al registrar aspirantes."
                    />
                    <CampoSelect
                        v-model="form.modalidad"
                        etiqueta="Modalidad (opcional)"
                        :opciones="opcionesModalidad"
                        vacio="Sin especificar"
                        :error="form.errors.modalidad"
                        ayuda="No delimita la oferta; solo la describe."
                    />
                </div>
            </TarjetaSeccion>

            <!-- ALTA: en qué campus, en conjunto (fan-out). -->
            <TarjetaSeccion v-if="!esEdicion" titulo="Dónde se ofrece" :icono="ICONOS.ubicacion">
                <CampoCasillas
                    v-model="form.campus_ids"
                    etiqueta="Campus"
                    :opciones="opciones(campus)"
                    :error="form.errors.campus_ids"
                    vacio="Elige al menos uno."
                />
                <p v-if="combinaciones > 0" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Se {{ combinaciones === 1 ? 'creará 1 oferta' : `crearán ${combinaciones} ofertas` }}
                    (las que ya existan se omiten).
                </p>
            </TarjetaSeccion>

            <!-- EDICIÓN: una oferta concreta. -->
            <TarjetaSeccion v-else titulo="Dónde" :icono="ICONOS.ubicacion">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="Campus"
                        requerido
                        :opciones="opciones(campus)"
                        vacio="Selecciona…"
                        :error="form.errors.campus_id"
                    />
                </div>
            </TarjetaSeccion>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear oferta(s)'" />
                <a
                    href="/academico/ofertas"
                    class="rounded-lg border px-5 py-2.5 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
