<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Postulacion {
    id: number;
    quien: string | null;
    matricula: string | null;
    etapa_id: number;
    etapa: string | null;
    acepta: boolean;
    promedio: string | null;
    fecha: string | null;
    estancia: { id: number; desde: string | null; hasta: string | null; concluida: boolean } | null;
}

const props = defineProps<{
    convocatoria: {
        id: number;
        titulo: string;
        institucion: string | null;
        direccion: string;
        es_saliente: boolean;
        periodo: string;
        cupo: number;
        libres: number;
        promedio_minimo: string | null;
        abierta: boolean;
        requisitos: string[];
    };
    postulaciones: Postulacion[];
    etapas: { id: number; nombre: string; acepta: boolean }[];
}>();

const postulando = ref(false);
const moviendo = ref<Postulacion | null>(null);
const abriendo = ref<Postulacion | null>(null);

const alta = useForm({
    matricula_oferta_id: null as number | null,
    persona_id: null as number | null,
    notas: '',
});

const cambio = useForm({ etapa_id: null as number | null });
const estancia = useForm({ fecha_inicio: hoyLocal(), fecha_fin: '' });

function postular(): void {
    alta.post(`/movilidad/convocatorias/${props.convocatoria.id}/postulaciones`, {
        preserveScroll: true,
        onSuccess: () => {
            postulando.value = false;
            alta.reset();
        },
    });
}

function abrirMovimiento(p: Postulacion): void {
    moviendo.value = p;
    cambio.reset();
    cambio.etapa_id = p.etapa_id;
    cambio.defaults();
}

function mover(): void {
    if (moviendo.value === null) return;

    cambio.put(`/movilidad/convocatorias/${props.convocatoria.id}/postulaciones/${moviendo.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            moviendo.value = null;
        },
    });
}

function abrirEstancia(): void {
    if (abriendo.value === null) return;

    estancia.transform((d) => ({ ...d, fecha_fin: d.fecha_fin === '' ? null : d.fecha_fin }))
        .post(`/movilidad/convocatorias/${props.convocatoria.id}/postulaciones/${abriendo.value.id}/estancia`, {
            preserveScroll: true,
            onSuccess: () => {
                abriendo.value = null;
            },
        });
}

function concluir(p: Postulacion): void {
    const fecha = prompt('¿En qué fecha concluyó la estancia?', hoyLocal());

    if (!fecha) return;

    router.put(
        `/movilidad/convocatorias/${props.convocatoria.id}/estancias/${p.estancia?.id}`,
        { concluida_en: fecha },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`Postulantes · ${convocatoria.titulo}`" />

    <AppLayout :titulo="convocatoria.titulo">
        <BotonVolver href="/movilidad/convocatorias" texto="Convocatorias" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ convocatoria.institucion }} · {{ convocatoria.direccion }} · {{ convocatoria.periodo }}
                    </p>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ convocatoria.libres }} de {{ convocatoria.cupo }} lugares libres
                        <span v-if="convocatoria.promedio_minimo">
                            · pide {{ convocatoria.promedio_minimo }} de promedio
                        </span>
                    </p>
                    <p v-if="convocatoria.requisitos.length" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                        Requisitos: {{ convocatoria.requisitos.join(', ') }}
                    </p>
                </div>

                <button
                    v-if="convocatoria.abierta"
                    type="button"
                    class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="postulando = true"
                >
                    Registrar postulante
                </button>
                <span v-else class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Cerrada: no admite postulaciones nuevas.
                </span>
            </div>
        </section>

        <TarjetaSeccion titulo="Postulantes" sin-relleno>
            <ul v-if="postulaciones.length">
                <li
                    v-for="p in postulaciones"
                    :key="p.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ p.quien }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                <template v-if="p.matricula">{{ p.matricula }} · </template>
                                <!--
                                    Congelado al postularse: el promedio de hoy
                                    no es con el que se le evaluó.
                                -->
                                <template v-if="p.promedio">promedio {{ p.promedio }} al postularse</template>
                                <template v-else>sin promedio</template>
                                <span v-if="p.fecha"> · {{ p.fecha }}</span>
                            </p>
                            <p v-if="p.estancia" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                Estancia desde el {{ p.estancia.desde }}
                                <template v-if="p.estancia.hasta"> hasta el {{ p.estancia.hasta }}</template>
                                <template v-if="p.estancia.concluida"> · concluida</template>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <PildoraEstado :texto="p.etapa" />
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="abrirMovimiento(p)"
                            >
                                Mover
                            </button>
                            <button
                                v-if="p.acepta && !p.estancia"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="abriendo = p"
                            >
                                Abrir estancia
                            </button>
                            <button
                                v-if="p.estancia && !p.estancia.concluida"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="concluir(p)"
                            >
                                Concluir
                            </button>
                            <!--
                                Sólo al saliente: a un entrante no se le escribe
                                historial académico nuestro, no tiene.
                            -->
                            <Link
                                v-if="p.estancia && convocatoria.es_saliente"
                                :href="`/movilidad/estancias/${p.estancia.id}/revalidaciones`"
                                class="rounded-lg px-3 py-1.5 text-xs font-medium"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            >
                                Revalidaciones
                            </Link>
                        </div>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía nadie se ha postulado.
            </p>
        </TarjetaSeccion>

        <Modal v-if="postulando" etiqueta="Registrar postulante" :formulario="alta" @cerrar="postulando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="postular">
                    <h2 class="text-base font-semibold">Registrar postulante</h2>

                    <!--
                        Al saliente se le busca por MATRÍCULA —el promedio sale
                        de su historial y se enseña al elegir— y al entrante por
                        persona, porque su historial está en su institución.
                    -->
                    <BuscadorRemoto
                        v-if="convocatoria.es_saliente"
                        v-model="alta.matricula_oferta_id"
                        :url="`/movilidad/convocatorias/${convocatoria.id}/candidatos`"
                        etiqueta="¿Quién se va?"
                        marcador="Nombre o matrícula…"
                        ayuda="Se enseña su promedio real: no se teclea, se calcula de su historial."
                        :error="alta.errors.matricula_oferta_id"
                    />
                    <BuscadorRemoto
                        v-else
                        v-model="alta.persona_id"
                        url="/buscar/alumnos"
                        etiqueta="¿Quién llega?"
                        marcador="Nombre o CURP…"
                        :error="alta.errors.persona_id"
                    />

                    <CampoTextarea v-model="alta.notas" etiqueta="Notas" :filas="2" :error="alta.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="alta.processing" texto="Registrar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="moviendo" etiqueta="Mover de etapa" :formulario="cambio" @cerrar="moviendo = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="mover">
                    <h2 class="text-base font-semibold">{{ moviendo.quien }}</h2>

                    <CampoSelect
                        v-model="cambio.etapa_id"
                        etiqueta="Etapa"
                        :opciones="etapas.map((e) => ({ valor: e.id, texto: e.nombre }))"
                        ayuda="Las que aceptan consumen un lugar del cupo."
                        :error="cambio.errors.etapa_id"
                    />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="cambio.processing" texto="Mover" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="abriendo" etiqueta="Abrir estancia" :formulario="estancia" @cerrar="abriendo = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="abrirEstancia">
                    <h2 class="text-base font-semibold">Estancia de {{ abriendo.quien }}</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Concluirla es lo que habilita revalidarle materias: mientras siga en curso,
                        las calificaciones de allá no están cerradas.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="estancia.fecha_inicio" etiqueta="Desde" tipo="date" requerido :error="estancia.errors.fecha_inicio" />
                        <CampoTexto v-model="estancia.fecha_fin" etiqueta="Hasta" tipo="date" ayuda="Si ya se sabe." :error="estancia.errors.fecha_fin" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="estancia.processing" texto="Abrir" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
