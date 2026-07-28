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
    turnos: { id: number; nombre: string }[];
    modalidades: { clave: string; nombre: string }[];
}>();

const esEdicion = computed(() => props.oferta !== null);

// El alta genera una oferta por combinación (fan-out): campus, modalidades y
// turnos se eligen en conjunto. La edición toca UNA oferta concreta, así que
// esos tres vuelven a ser de un solo valor.
const form = useForm(
    esEdicion.value
        ? {
              carrera_id: props.oferta!.carrera_id,
              plan_id: props.oferta!.plan_id,
              campus_id: props.oferta!.campus_id,
              turno_id: props.oferta!.turno_id,
              modalidad: props.oferta!.modalidad,
              estatus: props.oferta!.estatus,
          }
        : {
              carrera_id: null as number | null,
              plan_id: null as number | null,
              campus_ids: [] as number[],
              turno_ids: [] as number[],
              modalidades: [] as string[],
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

// Cuántas ofertas generará la combinación elegida, para avisar antes de guardar.
const combinaciones = computed(() => {
    if (esEdicion.value) {
        return 1;
    }

    const turnos = form.turno_ids.length || 1;

    return form.campus_ids.length * turnos * form.modalidades.length;
});

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
                    <template v-if="esEdicion">No puede repetirse la misma combinación de carrera, plan, campus y turno.</template>
                    <template v-else>
                        Elige la carrera y el plan, y los campus, modalidades y turnos donde se ofrecerá. Se
                        creará una oferta por cada combinación.
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
                </div>
            </section>

            <!-- ALTA: dónde y cómo, en conjunto (fan-out). -->
            <section v-if="!esEdicion" class="tarjeta p-6">
                <h2 class="text-base font-semibold">Dónde y cómo se ofrece</h2>

                <div class="mt-5 grid gap-6 sm:grid-cols-3">
                    <CampoCasillas
                        v-model="form.campus_ids"
                        etiqueta="Campus"
                        :opciones="opciones(campus)"
                        :error="form.errors.campus_ids"
                        vacio="Elige al menos uno."
                    />
                    <CampoCasillas
                        v-model="form.modalidades"
                        etiqueta="Modalidades"
                        :opciones="opcionesModalidad"
                        :error="form.errors.modalidades"
                    />
                    <CampoCasillas
                        v-model="form.turno_ids"
                        etiqueta="Turnos"
                        :opciones="opciones(turnos)"
                        :error="form.errors.turno_ids"
                        ayuda="Sin turno = una oferta sin turno específico."
                    />
                </div>

                <p v-if="combinaciones > 0" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Se {{ combinaciones === 1 ? 'creará 1 oferta' : `crearán ${combinaciones} ofertas` }}
                    (las que ya existan se omiten).
                </p>
            </section>

            <!-- EDICIÓN: una oferta concreta, un solo valor de cada cosa. -->
            <section v-else class="tarjeta p-6">
                <h2 class="text-base font-semibold">Dónde y cómo</h2>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="Campus"
                        requerido
                        :opciones="opciones(campus)"
                        vacio="Selecciona…"
                        :error="form.errors.campus_id"
                    />
                    <CampoSelect
                        v-model="form.turno_id"
                        etiqueta="Turno"
                        :opciones="opciones(turnos)"
                        vacio="Sin turno específico"
                        :error="form.errors.turno_id"
                    />
                    <CampoSelect
                        v-model="form.modalidad"
                        etiqueta="Modalidad"
                        requerido
                        :opciones="opcionesModalidad"
                        vacio="Selecciona…"
                        :error="form.errors.modalidad"
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
