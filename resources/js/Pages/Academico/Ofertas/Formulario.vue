<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';

const props = defineProps<{
    oferta: Record<string, any> | null;
    carreras: { id: number; nombre: string }[];
    planes: { id: number; nombre: string; clave: string; carrera_id: number }[];
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
              carrera_id: props.oferta!.carrera_id,
              plan_id: props.oferta!.plan_id,
              campus_id: props.oferta!.campus_id,
              modalidad: props.oferta!.modalidad ?? null,
              estatus: props.oferta!.estatus,
          }
        : {
              carrera_id: null as number | null,
              plan_id: null as number | null,
              campus_ids: [] as number[],
              modalidad: null as string | null,
              estatus: 'abierta',
          },
);

const planesDeLaCarrera = computed(() =>
    props.planes
        .filter((plan) => plan.carrera_id === form.carrera_id)
        .map((plan) => ({ valor: plan.id, texto: `${plan.nombre} (${plan.clave})` })),
);

watch(
    () => form.carrera_id,
    () => {
        if (!planesDeLaCarrera.value.some((plan) => plan.valor === form.plan_id)) {
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
        <NavAcademico />

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Qué se imparte</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    <template v-if="esEdicion">No puede repetirse la misma combinación de carrera, plan y campus.</template>
                    <template v-else>
                        Elige la carrera y el plan, y los campus donde se ofrecerá. Se creará una oferta por campus.
                    </template>
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.carrera_id"
                        etiqueta="Carrera"
                        requerido
                        :opciones="opciones(carreras)"
                        vacio="Selecciona…"
                        :error="form.errors.carrera_id"
                    />
                    <CampoSelect
                        v-model="form.plan_id"
                        etiqueta="Plan de estudios"
                        requerido
                        :opciones="planesDeLaCarrera"
                        vacio="Selecciona…"
                        :error="form.errors.plan_id"
                        :ayuda="
                            form.carrera_id === null
                                ? 'Elige primero una carrera.'
                                : planesDeLaCarrera.length === 0
                                  ? 'Esa carrera no tiene planes registrados.'
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
            </section>

            <!-- ALTA: en qué campus, en conjunto (fan-out). -->
            <section v-if="!esEdicion" class="tarjeta p-6">
                <h2 class="text-base font-semibold">Dónde se ofrece</h2>

                <div class="mt-5">
                    <CampoCasillas
                        v-model="form.campus_ids"
                        etiqueta="Campus"
                        :opciones="opciones(campus)"
                        :error="form.errors.campus_ids"
                        vacio="Elige al menos uno."
                    />
                </div>

                <p v-if="combinaciones > 0" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Se {{ combinaciones === 1 ? 'creará 1 oferta' : `crearán ${combinaciones} ofertas` }}
                    (las que ya existan se omiten).
                </p>
            </section>

            <!-- EDICIÓN: una oferta concreta. -->
            <section v-else class="tarjeta p-6">
                <h2 class="text-base font-semibold">Dónde</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="Campus"
                        requerido
                        :opciones="opciones(campus)"
                        vacio="Selecciona…"
                        :error="form.errors.campus_id"
                    />
                </div>
            </section>

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
