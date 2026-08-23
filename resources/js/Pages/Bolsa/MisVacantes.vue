<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Habilidad {
    nombre: string;
    indispensable: boolean;
}

interface Vacante {
    id: number;
    titulo: string;
    empresa: string | null;
    descripcion: string | null;
    modalidad: string | null;
    jornada: string | null;
    ubicacion: string | null;
    fecha_cierre: string | null;
    habilidades: Habilidad[];
    ya_postulado: boolean;
}

interface Postulacion {
    id: number;
    vacante: string | null;
    empresa: string | null;
    etapa: string | null;
    fecha: string | null;
    tiene_cv: boolean;
}

const props = defineProps<{
    /** ¿La escuela deja postularse sin pasar por ventanilla? */
    autogestiva: boolean;
    vacantes: Vacante[];
    postulaciones: Postulacion[];
    matriculas: { id: number; matricula: string; carrera: string | null }[];
}>();

const elegida = ref<Vacante | null>(null);

const form = useForm<{ matricula_oferta_id: number | null; carta_presentacion: string; cv: File | null }>({
    // Con una sola matrícula no hay nada que preguntar: se manda esa.
    matricula_oferta_id: props.matriculas.length === 1 ? props.matriculas[0].id : null,
    carta_presentacion: '',
    cv: null,
});

const abiertas = computed(() => props.vacantes.filter((v) => !v.ya_postulado));
const yaPostuladas = computed(() => props.vacantes.filter((v) => v.ya_postulado));

function abrir(v: Vacante): void {
    elegida.value = v;
    form.reset();
    form.matricula_oferta_id = props.matriculas.length === 1 ? props.matriculas[0].id : null;
    // Nace limpio: sin esto, la matrícula precargada haría que cerrar el
    // diálogo preguntara si queremos perder lo capturado sin haber escrito nada.
    form.defaults();
}

function enviar(): void {
    if (elegida.value === null) return;

    form.post(`/mis-vacantes/${elegida.value.id}/postularme`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            elegida.value = null;
        },
    });
}
</script>

<template>
    <Head title="Mis vacantes" />

    <AppLayout titulo="Bolsa de trabajo">
        <!--
            Lo primero es EN QUÉ VA lo que ya mandó: quien entra aquí por segunda
            vez viene a eso, no a mirar la lista otra vez.
        -->
        <TarjetaSeccion v-if="postulaciones.length" titulo="Mis postulaciones" sin-relleno class="mb-4">
            <ul>
                <li
                    v-for="p in postulaciones"
                    :key="p.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ p.vacante }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ p.empresa }}
                            <span v-if="p.fecha"> · te postulaste el {{ p.fecha }}</span>
                            <a
                                v-if="p.tiene_cv"
                                :href="`/mis-vacantes/postulaciones/${p.id}/cv`"
                                class="underline"
                                :style="{ color: 'var(--color-acento)' }"
                            >
                                · ver el currículum que mandaste
                            </a>
                        </p>
                    </div>

                    <PildoraEstado :texto="p.etapa" />
                </li>
            </ul>
        </TarjetaSeccion>

        <!--
            Con la postulación autogestiva apagada las vacantes SÍ se ven: sirven
            para enterarse. Lo que cambia es a dónde hay que ir, y eso se dice
            aquí en vez de dejar un botón que no existe.

            Sólo cuando hay alguna a la que postularse: sin vacantes, «para
            postularte a cualquiera de estas» manda a ventanilla por nada.
        -->
        <p
            v-if="!autogestiva && abiertas.length"
            class="mb-4 rounded-lg border px-4 py-3 text-sm"
            :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
        >
            Para postularte a cualquiera de estas vacantes acude a la oficina de vinculación de
            tu campus: ahí registran tu solicitud.
        </p>

        <TarjetaSeccion titulo="Vacantes para ti" sin-relleno>
            <ul v-if="abiertas.length">
                <li
                    v-for="v in abiertas"
                    :key="v.id"
                    class="border-t px-6 py-4 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ v.titulo }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ v.empresa }}
                                <span v-if="v.modalidad"> · {{ v.modalidad }}</span>
                                <span v-if="v.jornada"> · {{ v.jornada }}</span>
                                <span v-if="v.ubicacion"> · {{ v.ubicacion }}</span>
                                <span v-if="v.fecha_cierre"> · cierra el {{ v.fecha_cierre }}</span>
                            </p>
                        </div>

                        <button
                            v-if="autogestiva"
                            type="button"
                            class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            @click="abrir(v)"
                        >
                            Postularme
                        </button>
                    </div>

                    <p v-if="v.descripcion" class="mt-2 whitespace-pre-line text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ v.descripcion }}
                    </p>

                    <!--
                        Lo INDISPENSABLE se distingue de lo deseable: sin esa
                        marca, una lista de ocho requisitos no dice cuáles hay
                        que tener de verdad y desanima a quien sí calificaba.
                    -->
                    <ul v-if="v.habilidades.length" class="mt-2 flex flex-wrap gap-1.5">
                        <li
                            v-for="h in v.habilidades"
                            :key="h.nombre"
                            class="rounded-full px-2.5 py-0.5 text-xs"
                            :style="{
                                backgroundColor: h.indispensable
                                    ? 'color-mix(in srgb, var(--color-acento) 14%, transparent)'
                                    : 'color-mix(in srgb, var(--color-suave) 14%, transparent)',
                                color: h.indispensable ? 'var(--color-acento)' : 'var(--color-suave)',
                            }"
                        >
                            {{ h.nombre }}<template v-if="h.indispensable"> · indispensable</template>
                        </li>
                    </ul>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="yaPostuladas.length">
                    Ya te postulaste a todas las vacantes disponibles para tu carrera.
                </template>
                <template v-else>
                    Ahorita no hay vacantes publicadas para tu carrera. Vuelve a asomarte más adelante.
                </template>
            </p>
        </TarjetaSeccion>

        <Modal v-if="elegida" :etiqueta="`Postularme a ${elegida.titulo}`" :formulario="form" @cerrar="elegida = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="enviar">
                    <h2 class="text-base font-semibold">{{ elegida.titulo }}</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ elegida.empresa }}</p>

                    <!--
                        Sólo cuando hay más de una: con una sola carrera preguntar
                        de cuál se postula es hacer trabajo por nada.
                    -->
                    <CampoSelect
                        v-if="matriculas.length > 1"
                        v-model="form.matricula_oferta_id"
                        etiqueta="¿Con cuál de tus carreras?"
                        :opciones="matriculas.map((m) => ({ valor: m.id, texto: `${m.carrera ?? m.matricula}` }))"
                        vacio="Sin señalar"
                        :error="form.errors.matricula_oferta_id"
                    />

                    <CampoTextarea
                        v-model="form.carta_presentacion"
                        etiqueta="Carta de presentación"
                        :filas="5"
                        ayuda="Por qué te interesa y qué sabes hacer. Es lo primero que lee el reclutador."
                        :error="form.errors.carta_presentacion"
                    />

                    <div>
                        <label class="mb-1 block text-sm font-medium">Currículum (PDF o Word)</label>
                        <input
                            type="file"
                            accept=".pdf,.doc,.docx"
                            class="block w-full text-sm"
                            @change="form.cv = ($event.target as HTMLInputElement).files?.[0] ?? null"
                        >
                        <p v-if="form.errors.cv" class="mt-1 text-xs" :style="{ color: '#dc2626' }">{{ form.errors.cv }}</p>
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Enviar postulación" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
